FROM docker.io/php:8.2-fpm

COPY ./public /app

CMD ["php-fpm"]
