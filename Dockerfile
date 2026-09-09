FROM php:8.2-apache

# System packages:
# - cron          -> runs workers/* on schedule (alerts, ping, rollup, backup, ...)
# - iputils-ping  -> PingCheckWorker shells out to the `ping` binary
# - default-mysql-client -> BackupWorker shells out to `mysqldump`
RUN apt-get update && apt-get install -y --no-install-recommends \
        cron iputils-ping default-mysql-client unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: pdo_mysql (database), curl (Telegram/Turnstile),
# mbstring (validation), redis (optional cache layer, REDIS_ENABLED=1).
RUN docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis

RUN a2enmod rewrite headers

# Serve public/ as document root; allow the bundled .htaccess (clean URLs).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
COPY docker/apache.conf /etc/apache2/conf-available/servmon.conf
RUN a2enconf servmon

COPY docker/php.ini /usr/local/etc/php/conf.d/servmon.ini

WORKDIR /var/www/html
COPY . /var/www/html
# Pristine copy of config/ used by the entrypoint to seed the persisted
# config volume on first start (config/local.php must survive rebuilds).
RUN cp -a /var/www/html/config /var/www/html/config.dist

COPY docker/entrypoint.sh /usr/local/bin/servmon-entrypoint
RUN chmod +x /usr/local/bin/servmon-entrypoint

EXPOSE 80

ENTRYPOINT ["servmon-entrypoint"]
CMD ["apache2-foreground"]
