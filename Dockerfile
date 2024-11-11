FROM composer:2.0 AS composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins \
    --no-scripts  \
    --prefer-dist

FROM php:8.3.13-cli-alpine3.20 AS compile

RUN \
  apk update \
  && apk add --no-cache \
    make \
    automake \
    autoconf \
    gcc \
    g++ \
    git \
    linux-headers

ENV PHP_XDEBUG_VERSION="3.3.2"

FROM compile AS xdebug

RUN \
  git clone --depth 1 --branch $PHP_XDEBUG_VERSION https://github.com/xdebug/xdebug && \
  cd xdebug && \
  phpize && \
  ./configure && \
  make && make install

FROM php:8.3.13-cli-alpine3.20 AS final

ARG DEBUG=false
ENV DEBUG=$DEBUG

LABEL maintainer="team@appwrite.io"

WORKDIR /usr/src/code

RUN echo extension=xdebug.so >> /usr/local/etc/php/conf.d/xdebug.ini

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "opcache.enable_cli=1" >> $PHP_INI_DIR/php.ini && \
    echo "memory_limit=1024M" >> $PHP_INI_DIR/php.ini

COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=xdebug /usr/local/lib/php/extensions/no-debug-non-zts-20230831/xdebug.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/

COPY . /usr/src/code

RUN if [ "$DEBUG" = "true" ]; then cp /usr/src/code/dev/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini; fi
RUN if [ "$DEBUG" = "true" ]; then mkdir -p /tmp/xdebug; fi
RUN if [ "$DEBUG" = "false" ]; then rm -rf /usr/src/code/dev; fi
RUN if [ "$DEBUG" = "false" ]; then rm -f /usr/local/lib/php/extensions/no-debug-non-zts-20220829/xdebug.so; fi

CMD [ "tail", "-f", "/dev/null" ]
