FROM composer@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS composer
FROM php@sha256:8dbeb51c352cf1f70d5d50f3642c9fbed311e914475ea2382f5bc1525bef7bb3

# Quality checks use the confirmed PHP 8.3 target without mounting the Magento laboratory.
RUN apk add --no-cache git python3 bash unzip oniguruma libxml2 \
    && apk add --no-cache --virtual .lcvx-build-deps $PHPIZE_DEPS oniguruma-dev libxml2-dev \
    && docker-php-ext-install -j2 bcmath mbstring \
    && apk del .lcvx-build-deps
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENTRYPOINT ["composer"]
