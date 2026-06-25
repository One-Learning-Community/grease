# Self-contained Grease bench image for a REMOTE Linux daemon (NAS via docker context
# over SSH), where bind-mounting the local repo isn't possible. Bakes the repo + vendor
# and replicates the canonical grease-bench engine config EXACTLY (opcache + tracing JIT
# + Excimer, no Xdebug) so the numbers match the documented methodology.
FROM php:8.4-cli
RUN set -eux; \
    docker-php-ext-install opcache; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip; \
    pecl install excimer; \
    docker-php-ext-enable excimer; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.jit=tracing'; \
      echo 'opcache.jit_buffer_size=64M'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
      echo 'realpath_cache_size=4096k'; \
      echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/zz-grease-bench.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . /app
RUN composer install --no-interaction --prefer-dist --no-progress
CMD ["sleep", "infinity"]
