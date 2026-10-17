FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql gd \
 && a2enmod rewrite headers expires \
 && rm -rf /var/lib/apt/lists/*
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
RUN mkdir -p /var/www/html/assets/uploads /var/www/html/storage/uploads \
 && chown -R www-data:www-data /var/www/html/assets/uploads /var/www/html/storage/uploads \
 && chmod 750 /var/www/html/storage/uploads
EXPOSE 80
