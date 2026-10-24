# CiegoLink Infrastructure Hub - web app (PHP 8.3 + Apache)
FROM php:8.3-apache-bookworm

ENV INFRA_ROOT=/var/www/ciegolinkinfrahub \
    TZ=Europe/Zurich \
    TRUSTED_PROXIES="10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.1" \
    APACHE_DOCUMENT_ROOT=/var/www/ciegolinkinfrahub/public

# sqlite3 for backups, tzdata for local time, iputils-ping for the status check
RUN apt-get update \
 && apt-get install -y --no-install-recommends sqlite3 tzdata iputils-ping \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install -j"$(nproc)" opcache >/dev/null \
 && a2enmod headers remoteip proxy proxy_http proxy_wstunnel rewrite >/dev/null \
 && a2dissite 000-default >/dev/null \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && php -m | grep -qi pdo_sqlite && php -m | grep -qi openssl

COPY docker/php.ini        $PHP_INI_DIR/conf.d/zz-ciegolinkinfrahub.ini
COPY docker/apache.conf    /etc/apache2/sites-available/ciegolinkinfrahub.conf
COPY docker/security.conf  /etc/apache2/conf-available/zz-ciegolinkinfrahub-security.conf
COPY docker/entrypoint.sh  /usr/local/bin/ciegolinkinfrahub-entrypoint
RUN a2ensite ciegolinkinfrahub >/dev/null && a2enconf zz-ciegolinkinfrahub-security >/dev/null \
 && chmod 755 /usr/local/bin/ciegolinkinfrahub-entrypoint

# app code (read-only), config/ and data/ are volumes
COPY --chown=root:root core.php VERSION CHANGELOG.md ${INFRA_ROOT}/
COPY --chown=root:root bin     ${INFRA_ROOT}/bin
COPY --chown=root:root public  ${INFRA_ROOT}/public
RUN find ${INFRA_ROOT} -type d -exec chmod 755 {} + \
 && find ${INFRA_ROOT} -type f -exec chmod 644 {} + \
 && install -d -m 750 -o root -g www-data ${INFRA_ROOT}/config \
 && install -d -m 700 -o www-data -g www-data ${INFRA_ROOT}/data

VOLUME ["/var/www/ciegolinkinfrahub/config", "/var/www/ciegolinkinfrahub/data"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS -o /dev/null http://127.0.0.1/manual/ || exit 1

ENTRYPOINT ["ciegolinkinfrahub-entrypoint"]
CMD ["apache2-foreground"]
