FROM docker.io/php:8.2-fpm

COPY ./public /opt/index/public

WORKDIR /opt/index/public

CMD ["php-fpm"]
