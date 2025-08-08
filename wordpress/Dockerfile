FROM wordpress:php8.2-fpm

# Cài Xdebug qua PECL
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug

# Bật cấu hình Xdebug
COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

