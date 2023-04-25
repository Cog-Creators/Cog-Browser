FROM docker.io/php:7.4-fpm

COPY ./public /opt/index/public

WORKDIR /opt/index/public

CMD ["php-fpm"]
