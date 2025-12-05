FROM composer:2.0 AS composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist
    
FROM php:8.4-cli-alpine AS compile

ENV PHP_REDIS_VERSION=6.1.0 \
    PHP_SWOOLE_VERSION=v6.0.2 \
    PHP_MONGO_VERSION=2.1.1
    
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apk update \
  && apk add --no-cache postgresql-libs postgresql-dev make automake autoconf gcc g++ git brotli-dev \
  && docker-php-ext-install opcache pgsql pdo_mysql pdo_pgsql \
  && rm -rf /var/cache/apk/*

# Redis Extension
FROM compile AS redis
RUN \
  git clone --depth 1 --branch $PHP_REDIS_VERSION https://github.com/phpredis/phpredis.git \
  && cd phpredis \
  && phpize \
  && ./configure \
  && make && make install

## Swoole Extension
FROM compile AS swoole
RUN \
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git \
  && cd swoole-src \
  && phpize \
  && ./configure --enable-http2 \
  && make && make install

## MongoDB Extension
FROM compile AS mongodb
RUN \
  git clone --depth 1 --branch $PHP_MONGO_VERSION https://github.com/mongodb/mongo-php-driver.git \
  && cd mongo-php-driver \
  && git submodule update --init \
  && phpize \
  && ./configure \
  && make && make install

FROM compile AS final

LABEL maintainer="team@appwrite.io"

WORKDIR /usr/src/code

RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini
RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini
RUN echo extension=mongodb.so >> /usr/local/etc/php/conf.d/mongodb.ini

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN echo "opcache.enable_cli=1" >> $PHP_INI_DIR/php.ini

RUN echo "memory_limit=1024M" >> $PHP_INI_DIR/php.ini

COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=swoole /usr/local/lib/php/extensions/no-debug-non-zts-20240924/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/
COPY --from=redis /usr/local/lib/php/extensions/no-debug-non-zts-20240924/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/
COPY --from=mongodb /usr/local/lib/php/extensions/no-debug-non-zts-20240924/mongodb.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/

# Add Source Code
COPY . /usr/src/code

CMD [ "tail", "-f", "/dev/null" ]
