#!/usr/bin/env bash
# CiegoLink Infrastructure Hub - updater
#
#   sudo bash update.sh                  check for a new version, show the changes, back up, update
#   sudo bash update.sh --check          only show whether an update is available
#   sudo bash update.sh --yes            update without questions
#   sudo bash update.sh --source FILE    update from a downloaded .zip/.tar.gz or a folder (installation without git)
#   sudo bash update.sh --rollback       undo the last update (code and database)
#   sudo bash update.sh --install-timer [daily|weekly]   automatic updates (new versions only)
#   sudo bash update.sh --remove-timer   stop automatic updates
#
# Options: --no-software (backup without the software library files), --keep N (backups to keep, default 10)
# Backups: $BACKUP_DIR (default /var/backups/ciegolinkinfrahub)
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/ciegolinkinfrahub}"
STATE_DIR="$DIR/.update"
LOG="$STATE_DIR/update.log"
APP=/var/www/ciegolinkinfrahub
UNIT=ciegolinkinfrahub-update

REBUILD=n MODE=update YES=n AUTO=n SOURCE="" SOFTWARE=y KEEP=10 TIMER=weekly
while [ $# -gt 0 ]; do
  case "$1" in
    --check) MODE=check ;;
    --yes|-y) YES=y ;;
    --auto) AUTO=y; YES=y ;;
    --source) SOURCE="${2:?--source needs a file or folder}"; shift ;;
    --rollback) MODE=rollback ;;
    --install-timer) MODE=timer; case "${2:-}" in daily|weekly) TIMER="$2"; shift ;; esac ;;
    --remove-timer) MODE=untimer ;;
    --no-software) SOFTWARE=n ;;
    --keep) KEEP="${2:?--keep needs a number}"; shift; [[ "$KEEP" =~ ^[0-9]+$ ]] || { echo "--keep needs a number" >&2; exit 2; } ;;
    -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
  esac
  shift
done

mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR"
say()  { printf '%s\n' "$*"; [ "$AUTO" = y ] && printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; return 0; }
fail() { say ""; say "Error: $*"; exit 1; }
ask()  { [ "$YES" = y ] && return 0; local a; read -r -p "$1 (y/n) [n]: " a; [ "$a" = y ] || [ "$a" = j ]; }

# only one run at a time (manual run and timer)
exec 9>"$STATE_DIR/lock"
command -v flock >/dev/null && { flock -n 9 || fail "Another update is running."; }

# --- automatic updates (systemd timer) ---------------------------------------
if [ "$MODE" = timer ] || [ "$MODE" = untimer ]; then
  [ "$(id -u)" = 0 ] || fail "Run with sudo."
  command -v systemctl >/dev/null || fail "systemd is required for automatic updates. Alternative: a cron job with 'bash $DIR/update.sh --auto'."
  if [ "$MODE" = untimer ]; then
    systemctl disable --now "$UNIT.timer" 2>/dev/null || true
    rm -f "/etc/systemd/system/$UNIT.timer" "/etc/systemd/system/$UNIT.service"
    systemctl daemon-reload
    say "Automatic updates are off."; exit 0
  fi
  WHEN='Sun *-*-* 04:00:00'; [ "$TIMER" = daily ] && WHEN='*-*-* 04:00:00'
  cat > "/etc/systemd/system/$UNIT.service" <<EOF
[Unit]
Description=CiegoLink Infrastructure Hub - automatic update
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=$DIR
Environment=BACKUP_DIR=$BACKUP_DIR
ExecStart=/bin/bash $DIR/update.sh --auto --keep $KEEP$([ "$SOFTWARE" = n ] && printf ' --no-software')
EOF
  cat > "/etc/systemd/system/$UNIT.timer" <<EOF
[Unit]
Description=CiegoLink Infrastructure Hub - automatic update ($TIMER)

[Timer]
OnCalendar=$WHEN
RandomizedDelaySec=30min
Persistent=true

[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
  systemctl enable --now "$UNIT.timer" >/dev/null
  say "Automatic updates are on ($TIMER, around 04:00). Only new versions (VERSION changed) are installed;"
  say "a backup is made first and a failed update is rolled back automatically."
  say "  Next run:  systemctl list-timers $UNIT.timer"
  say "  Log:       journalctl -u $UNIT  or  $LOG"
  say "  Run now:   sudo systemctl start $UNIT"
  say "  Turn off:  sudo bash update.sh --remove-timer"
  exit 0
fi

# --- checks --------------------------------------------------------------------
command -v docker >/dev/null || fail "Docker is not installed."
docker compose version >/dev/null 2>&1 || fail "Docker Compose plugin is missing (docker compose)."
docker info >/dev/null 2>&1 || fail "No access to Docker. Run with sudo."
[ -f .env ] || fail ".env is missing - this folder is not an installed hub (install with install.sh)."
[ -f VERSION ] || fail "VERSION is missing."

CUR="$(tr -d '[:space:]' < VERSION)"
GIT=n; [ -z "$SOURCE" ] && [ -d .git ] && GIT=y
# git runs as the owner of the repository, so files do not change owner when run with sudo
OWNER="$(stat -c %U "$DIR")"
g() { if [ "$(id -u)" = 0 ] && [ "$OWNER" != root ] && command -v sudo >/dev/null; then sudo -u "$OWNER" git -C "$DIR" "$@"; else git -c safe.directory="$DIR" -C "$DIR" "$@"; fi; }

wait_healthy() {
  local cid state=starting
  cid="$(docker compose ps -q hub)"; [ -n "$cid" ] || return 1
  for _ in $(seq 1 90); do
    state="$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || echo starting)"
    [ "$state" = healthy ] && return 0
    [ "$state" = unhealthy ] && return 1
    sleep 2
  done
  return 1
}

# replaces the database with a backup; hub must be stopped
restore_db() {
  local b="$1"
  [ -f "$b/infra.sqlite" ] || fail "No database in $b."
  docker compose stop hub >/dev/null
  docker compose run --rm --no-deps -T -u 0 -v "$b:/restore:ro" --entrypoint sh hub -c \
    "cp /restore/infra.sqlite $APP/data/infra.sqlite && rm -f $APP/data/infra.sqlite-wal $APP/data/infra.sqlite-shm && chown www-data:www-data $APP/data/infra.sqlite && chmod 600 $APP/data/infra.sqlite" >/dev/null
}

# --- rollback ------------------------------------------------------------------
rollback() {
  [ -f "$STATE_DIR/last" ] || fail "No update to roll back (.update/last missing)."
  # shellcheck disable=SC1091
  . "$STATE_DIR/last"
  say "Rolling back $NEW_VERSION -> $PREV_VERSION (backup $BACKUP) ..."
  if [ "$PREV_MODE" = rebuild ]; then
    say "The files were already new before this update; only the database is restored. Old code: git log, then git checkout <commit>."
  elif [ "$PREV_MODE" = git ]; then
    g reset --hard --quiet "$PREV_COMMIT"
  else
    tar -xzf "$BACKUP/code.tar.gz" -C "$DIR"
  fi
  docker compose build >/dev/null
  restore_db "$BACKUP"
  docker compose up -d >/dev/null
  wait_healthy || fail "The hub does not start after the rollback. Details: docker compose logs hub"
  echo "$NEW_COMMIT" > "$STATE_DIR/skip"
  rm -f "$STATE_DIR/last"
  say "Rolled back to $PREV_VERSION. Data changed after the update was replaced by the backup."
}
if [ "$MODE" = rollback ]; then
  [ "$(id -u)" = 0 ] || [ -w /var/run/docker.sock ] || fail "Run with sudo."
  ask "Restore the code and the database from before the last update?" || exit 0
  rollback; exit 0
fi

# --- find the new version ------------------------------------------------------
TMP=""; cleanup() { [ -n "$TMP" ] && rm -rf "$TMP"; return 0; }; trap cleanup EXIT
if [ "$GIT" = y ]; then
  command -v git >/dev/null || fail "git is missing."
  BRANCH="$(g rev-parse --abbrev-ref HEAD)"; [ "$BRANCH" != HEAD ] || fail "No branch checked out (detached HEAD)."
  if ! g diff --quiet || ! g diff --cached --quiet; then
    g status --short --untracked-files=no
    fail "Project files were changed locally (see above). Undo with 'git checkout -- <file>' or commit them first."
  fi
  g fetch --quiet origin "$BRANCH" || fail "git fetch failed. Is the server online and the repository reachable?"
  REMOTE="origin/$BRANCH"
  NEW_COMMIT="$(g rev-parse "$REMOTE")"; PREV_COMMIT="$(g rev-parse HEAD)"
  if [ "$NEW_COMMIT" = "$PREV_COMMIT" ]; then
    # files already new (e.g. git pull by hand), containers still old: build without new files
    RUN_VER="$(docker compose exec -T hub cat "$APP/VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
    if [ -n "$RUN_VER" ] && [ "$RUN_VER" != "$CUR" ]; then
      say "The files are version $CUR, but $RUN_VER is running - building and restarting."
      REBUILD=y
    else say "Up to date (version $CUR)."; exit 0; fi
  fi
  g merge-base --is-ancestor HEAD "$REMOTE" || fail "Local commits that are not on GitHub - update by hand (git pull)."
  NEW="$(g show "$REMOTE:VERSION" | tr -d '[:space:]')"
  [ "$REBUILD" = y ] && { NEW="$CUR"; CUR="$RUN_VER"; }
  CHANGES="$(g log --format='  - %s' "HEAD..$REMOTE" 2>/dev/null | head -30 || true)"
else
  [ -n "$SOURCE" ] || fail "This folder is no git checkout. Download the new version and run: sudo bash update.sh --source <file.zip|folder>"
  TMP="$(mktemp -d)"
  if [ -d "$SOURCE" ]; then cp -a "$SOURCE"/. "$TMP"/
  else
    [ -f "$SOURCE" ] || fail "$SOURCE not found."
    case "$SOURCE" in
      *.zip) command -v unzip >/dev/null || fail "unzip is missing (sudo apt install unzip)."; unzip -q "$SOURCE" -d "$TMP" ;;
      *.tar.gz|*.tgz) tar -xzf "$SOURCE" -C "$TMP" ;;
      *) fail "Use a .zip, .tar.gz or a folder." ;;
    esac
  fi
  # GitHub archives contain one top folder
  SRC="$TMP"; [ -f "$SRC/VERSION" ] || SRC="$(find "$TMP" -mindepth 2 -maxdepth 2 -name VERSION -printf '%h\n' | head -1 || true)"
  [ -n "$SRC" ] && [ -f "$SRC/VERSION" ] && [ -f "$SRC/docker-compose.yml" ] && [ -f "$SRC/core.php" ] || fail "$SOURCE does not contain the Infrastructure Hub."
  NEW="$(tr -d '[:space:]' < "$SRC/VERSION")"
  NEW_COMMIT="source:$NEW"; PREV_COMMIT=""; CHANGES=""
fi

newer() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -1)" = "$1" ]; }
if [ "$AUTO" = y ]; then
  # unattended: only real releases, never a version that was rolled back
  newer "$NEW" "$CUR" || { say "No new version (installed $CUR, available $NEW)."; exit 0; }
  [ "$(cat "$STATE_DIR/skip" 2>/dev/null)" != "$NEW_COMMIT" ] || { say "Version $NEW was rolled back before - skipped. Update by hand once the problem is fixed."; exit 0; }
fi
if [ -z "$SOURCE" ] || [ "$NEW" != "$CUR" ]; then :; else say "Version $NEW is already installed."; [ "$YES" = y ] && exit 0; fi
newer "$CUR" "$NEW" && [ "$NEW" != "$CUR" ] && say "Warning: $NEW is OLDER than the installed $CUR. For going back use --rollback."

say ""
say "=== CiegoLink Infrastructure Hub - Update ==="
say "Installed: $CUR    Available: $NEW"
if [ -n "$CHANGES" ]; then say ""; say "Changes:"; say "$CHANGES"; fi
if [ "$NEW" != "$CUR" ]; then
  NOTES="$( { if [ "$GIT" = y ]; then g show "$REMOTE:CHANGELOG.md"; else cat "$SRC/CHANGELOG.md"; fi; } 2>/dev/null | awk -v cur="$CUR" '/^## /{split($2,a," ");if(a[1]==cur)exit;p=1}p' | head -40 || true)"
  [ -n "$NOTES" ] && { say ""; say "From CHANGELOG.md:"; say "$NOTES"; }
fi
[ "$MODE" = check ] && exit 0
say ""
[ "$(id -u)" = 0 ] || [ -w /var/run/docker.sock ] || fail "Run with sudo."
ask "Make a backup and update now?" || exit 0

# --- backup ----------------------------------------------------------------------
say ""; say "1/4 Backup ..."
docker compose up -d >/dev/null
wait_healthy || fail "The hub is not running properly before the update - not touching anything. Details: docker compose logs hub"
umask 077
mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
B="$BACKUP_DIR/$(date +%F_%H%M%S)_v$CUR"; mkdir -p "$B"
docker compose exec -T -u www-data hub sqlite3 "$APP/data/infra.sqlite" ".backup '$APP/data/update-backup.sqlite'"
docker compose cp hub:"$APP/data/update-backup.sqlite" "$B/infra.sqlite" >/dev/null
docker compose exec -T -u www-data hub rm -f "$APP/data/update-backup.sqlite"
CHECK="$(docker compose exec -T -u www-data hub sqlite3 "$APP/data/infra.sqlite" 'PRAGMA integrity_check' 2>&1 || true)"
[ "$(printf '%s' "$CHECK" | tr -d '[:space:]')" = ok ] || fail "Database integrity check failed - update stopped. Backup: $B"
docker compose cp hub:"$APP/config/config.php" "$B/config.php" >/dev/null
[ "$SOFTWARE" = y ] && { docker compose cp hub:"$APP/data/software" "$B/software" >/dev/null 2>&1 || true; }
cp .env "$B/env"
tar -czf "$B/code.tar.gz" --exclude=./.git --exclude=./.update --exclude=./.env -C "$DIR" .
chmod -R go-rwx "$B"
[ -s "$B/infra.sqlite" ] && [ -s "$B/config.php" ] || fail "Backup incomplete - update stopped. ($B)"
say "    $B ($(du -sh "$B" | cut -f1))"
cat > "$STATE_DIR/last" <<EOF
PREV_MODE=$([ "$REBUILD" = y ] && echo rebuild || { [ "$GIT" = y ] && echo git || echo source; })
PREV_COMMIT=$PREV_COMMIT
PREV_VERSION=$CUR
NEW_COMMIT='$NEW_COMMIT'
NEW_VERSION=$NEW
BACKUP='$B'
EOF
# keep the newest $KEEP backups
if [ "$KEEP" -gt 0 ]; then
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*_v*' | sort | head -n -"$KEEP" | xargs -r rm -rf
fi

# --- new code ------------------------------------------------------------------
say "2/4 New files ..."
if [ "$REBUILD" = y ]; then
  say "    (files are already up to date)"
elif [ "$GIT" = y ]; then
  g merge --ff-only --quiet "$REMOTE" || fail "git merge failed - nothing was changed. Backup: $B"
else
  tar -C "$SRC" --exclude=./.git --exclude=./.env --exclude=./.update --exclude=./apache-ciegolinkinfrahub.conf -cf - . | tar -C "$DIR" -xf -
fi

# --- build and start -------------------------------------------------------------
say "3/4 Build and restart (takes a few minutes) ..."
if ! docker compose build --pull >"$STATE_DIR/build.log" 2>&1; then
  say "    Build failed (see $STATE_DIR/build.log) - rolling back."
  rollback; exit 1
fi
say "4/4 Check ..."
OK=n RUNNING=""
if docker compose up -d --remove-orphans >"$STATE_DIR/start.log" 2>&1 && wait_healthy; then
  RUNNING="$(docker compose exec -T hub cat "$APP/VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
  [ "$RUNNING" = "$NEW" ] && OK=y
fi
if [ "$OK" != y ]; then
  say "    The new version does not start properly (running: ${RUNNING:-?}) - rolling back."
  docker compose logs --tail=40 hub > "$STATE_DIR/failed-start.log" 2>&1 || true
  rollback; exit 1
fi
rm -f "$STATE_DIR/skip"
docker image prune -f >/dev/null 2>&1 || true

say ""
say "Updated to $NEW. Backup of the old state: $B"
say "Undo: sudo bash update.sh --rollback"
