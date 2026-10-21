FROM docker.io/library/php:7.0-fpm

RUN docker-php-ext-install -j1 mysqli pdo_mysql

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
