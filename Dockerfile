FROM docker.io/php:7.4-fpm AS base

COPY ./public /opt/index/public

WORKDIR /opt/index/public

CMD ["php-fpm"]

FROM base AS dev

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

FROM base

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
