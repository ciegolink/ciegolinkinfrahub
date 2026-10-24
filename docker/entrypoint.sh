#!/bin/sh
# Prepare config/ and data/, then start Apache
set -eu
ROOT=/var/www/ciegolinkinfrahub
CFG="$ROOT/config/config.php"

install -d -m 750 -o root -g www-data "$ROOT/config"
install -d -m 700 -o www-data -g www-data "$ROOT/data" "$ROOT/data/sessions"

if [ ! -f "$CFG" ]; then
  KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
  umask 027
  cat > "$CFG" <<PHP
<?php
// Generated on first start. Contains the encryption key, keep a backup.
\$gw = (string) getenv('GATEWAY_SECRET');
return [
    'data_dir' => '/var/www/ciegolinkinfrahub/data',
    'key' => '$KEY',
    'allow_http_local' => false,
    'timezone' => getenv('TZ') ?: 'Europe/Zurich',
    'software_max_mb' => (int) (getenv('SOFTWARE_MAX_MB') ?: 2048),
    'ssh_gateway' => strlen(\$gw) >= 32 ? ['secret' => \$gw, 'url' => getenv('GATEWAY_URL') ?: 'http://ssh-gateway:8022'] : null,
];
PHP
  echo "ciegolinkinfrahub: created config/config.php with a new encryption key - back it up."
fi
chown root:www-data "$CFG" && chmod 640 "$CFG"

# create/upgrade schema
su -s /bin/sh www-data -c "php -r 'require \"$ROOT/core.php\"; schema(); migrate();'"
[ -f "$ROOT/data/infra.sqlite" ] && chmod 600 "$ROOT/data/infra.sqlite"

USERS="$(su -s /bin/sh www-data -c "php -r 'require \"$ROOT/core.php\"; echo query(\"SELECT COUNT(*) FROM users\")->fetchColumn();'")"
if [ "$USERS" = "0" ]; then
  # first start: create admin (password change + 2FA forced at first sign-in)
  ADMIN_USER="${INFRA_ADMIN_USER:-admin}"
  ADMIN_PASS="${INFRA_ADMIN_PASSWORD:-}"
  GENERATED=0
  if [ -z "$ADMIN_PASS" ]; then ADMIN_PASS="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(15)),"+/","Kx"),"=");')"; GENERATED=1; fi
  INFRA_U="$ADMIN_USER" INFRA_P="$ADMIN_PASS" su -s /bin/sh -p www-data -c "php -r '
    require \"$ROOT/core.php\";
    \$u=(string)getenv(\"INFRA_U\"); \$p=(string)getenv(\"INFRA_P\");
    if(!preg_match(\"/^[A-Za-z0-9._@-]{3,80}\$/D\",\$u)){fwrite(STDERR,\"ciegolinkinfrahub: INFRA_ADMIN_USER invalid\\n\");exit(1);}
    if(strlen(\$p)<14||strlen(\$p)>72){fwrite(STDERR,\"ciegolinkinfrahub: INFRA_ADMIN_PASSWORD needs 14-72 characters\\n\");exit(1);}
    query(\"INSERT INTO users(username,hash,role) VALUES(?,?,?)\",[\$u,password_hash(\$p,PASSWORD_DEFAULT),\"admin\"]);
    audit(\"user_created\",\$u,\"CLI\");'"
  echo "================================================================"
  echo " ciegolinkinfrahub: first administrator created"
  echo "   Username:           $ADMIN_USER"
  if [ "$GENERATED" = "1" ]; then echo "   Temporary password: $ADMIN_PASS"; else echo "   Temporary password: from INFRA_ADMIN_PASSWORD in .env"; fi
  echo " Sign in (leave 2FA empty), then set your own password and 2FA."
  echo "================================================================"
fi

exec docker-php-entrypoint "$@"
