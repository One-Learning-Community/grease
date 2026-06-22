# Linux benchmarking image for Grease's Blade/render benches.
#
# macOS is the wrong place to measure filesystem-sensitive work: the /var→/private/var
# symlink confuses opcache's realpath keying, and CLI opcache behaves unlike production
# FPM. Nobody deploys on a Mac — so we measure on Linux, with opcache + JIT + Excimer
# exactly as a real PHP 8.4 deployment would run.
#
#   docker build -t grease-bench benchmarks/docker
#   docker run --rm -v "$PWD":/app -w /app grease-bench php benchmarks/blade.php
#
# JIT/opcache flags are baked into the image's php.ini (see below), and Xdebug is absent,
# so a bare `php` invocation already measures the honest, production-shaped path.

FROM php:8.4-cli

# opcache (bundled) + Excimer (sampling profiler) — the latter needs the build deps.
RUN set -eux; \
    docker-php-ext-install opcache; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install excimer; \
    docker-php-ext-enable excimer; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

# Production-shaped engine config: opcache on (CLI too), JIT tracing, timestamp validation
# on (the default), and a realpath cache like a real box. No Xdebug.
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

WORKDIR /app
