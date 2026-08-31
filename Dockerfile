FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# The base image's default vhost inherits AllowOverride None for /var/www/,
# which makes it silently ignore .htaccess (the rewrite-everything-to-
# index.php rule never applies) and can leave requests hitting Apache's
# stricter default access rules -- a common cause of an immediate 403 on a
# fresh php:8.2-apache container. Grant this app's directory its own
# explicit, permissive config instead of depending on the base image's
# inherited defaults.
RUN { \
    echo '<Directory /var/www/html/>'; \
    echo '    Options Indexes FollowSymLinks'; \
    echo '    AllowOverride All'; \
    echo '    Require all granted'; \
    echo '</Directory>'; \
    } > /etc/apache2/conf-available/paragrafy.conf \
    && a2enconf paragrafy

WORKDIR /var/www/html

# Build from the local checkout, not a fresh git clone -- cloning from GitHub
# at build time meant Docker's layer cache could silently keep serving an old
# commit even after `docker compose up -d --build` (nothing in the Dockerfile
# changes just because the remote repo did, so the RUN git clone layer never
# invalidates on its own). Copying the local context always picks up the
# code you actually have checked out. See .dockerignore for what's excluded
# (in particular: local data/, .git/, and any local .env files).
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && chmod +x /var/www/html/docker-entrypoint.sh

# Persistent data (DB, config.php, backups, .env) lives outside the code tree
# so it survives image rebuilds -- see PARAGRAFY_DATA_DIR in docker-compose.yaml.
ENV PARAGRAFY_DATA_DIR=/var/www/html/data

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
