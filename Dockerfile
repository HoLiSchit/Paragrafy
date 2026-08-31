FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_sqlite

RUN a2enmod rewrite

WORKDIR /var/www/html

RUN git clone https://github.com/mineco-de/Paragrafy.git /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} +

# Persistent data (DB, config.php, backups, .env) lives outside the code tree
# so it survives image rebuilds -- see PARAGRAFY_DATA_DIR in docker-compose.yaml.
ENV PARAGRAFY_DATA_DIR=/var/www/html/data

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]