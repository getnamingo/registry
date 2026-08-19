# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

ARG PHP_EXTENSION_JOBS=2

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bind9-dnsutils \
        bind9-utils \
        bzip2 \
        ca-certificates \
        curl \
        gettext-base \
        git \
        gosu \
        gnupg \
        mariadb-client \
        netcat-openbsd \
        openssh-client \
        openssl \
        postgresql-client \
        procps \
        tini \
        unzip \
        whois; \
    IPE_PROCESSOR_COUNT="$PHP_EXTENSION_JOBS" install-php-extensions \
        bcmath \
        curl \
        ds \
        ftp \
        gd \
        gettext \
        gmp \
        gnupg \
        igbinary \
        imap \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        protobuf \
        redis \
        soap \
        sockets \
        swoole \
        uuid \
        xml \
        zip; \
    rm -f /usr/local/bin/install-php-extensions; \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

FROM php-base AS vendor-builder

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

WORKDIR /opt/registry
COPY . /opt/registry

RUN set -eux; \
    for component in cp epp rdap das whois/port43 automation; do \
        composer --working-dir="${component}" install \
            --no-dev \
            --no-interaction \
            --no-progress \
            --prefer-dist \
            --optimize-autoloader; \
    done; \
    composer --working-dir=whois/web require \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        altcha-org/altcha:^2.1; \
    rm -rf /tmp/composer

FROM php-base AS namingo

LABEL org.opencontainers.image.title="Namingo Registry" \
      org.opencontainers.image.description="Container runtime for the Namingo domain registry platform" \
      org.opencontainers.image.source="https://github.com/getnamingo/registry" \
      org.opencontainers.image.licenses="MIT"

ENV TZ=UTC

WORKDIR /opt/registry
COPY --from=vendor-builder /opt/registry /opt/registry
RUN set -eux; \
    mkdir -p /var/www /var/log/namingo /opt/escrow /opt/reporting /var/lib/bind /run/namingo; \
    cp -a /opt/registry/cp /var/www/cp; \
    cp -a /opt/registry/whois/web /var/www/whois; \
    ln -s /run/namingo/epp-config.php /opt/registry/epp/config.php; \
    ln -s /run/namingo/rdap-config.php /opt/registry/rdap/config.php; \
    ln -s /run/namingo/das-config.php /opt/registry/das/config.php; \
    ln -s /run/namingo/whois-config.php /opt/registry/whois/port43/config.php; \
    ln -s /run/namingo/automation-config.php /opt/registry/automation/config.php; \
    ln -s /run/namingo/web-whois-config.php /var/www/whois/config.php; \
    ln -s /run/namingo/panel.env /var/www/cp/.env; \
    chown -R www-data:www-data \
        /var/log/namingo \
        /opt/escrow \
        /opt/reporting \
        /var/lib/bind \
        /var/www/cp/cache \
        /var/www/cp/resources \
        /run/namingo

COPY docker/php/namingo.ini /usr/local/etc/php/conf.d/zz-namingo.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-namingo.conf
COPY docker/config /etc/namingo/overrides
COPY docker/bin /usr/local/lib/namingo

RUN set -eux; \
    chmod 0755 /usr/local/lib/namingo/*.sh; \
    chmod 0755 /usr/local/lib/namingo/entrypoint; \
    chmod 0755 /usr/local/lib/namingo/init.php; \
    ln -s /usr/local/lib/namingo/entrypoint /usr/local/bin/namingo-entrypoint; \
    ln -s /usr/local/lib/namingo/init.php /usr/local/bin/namingo-init; \
    ln -s /usr/local/lib/namingo/epp-supervisor.sh /usr/local/bin/namingo-epp; \
    ln -s /usr/local/lib/namingo/message-producer.sh /usr/local/bin/namingo-message-producer; \
    ln -s /usr/local/lib/namingo/cron-loop.sh /usr/local/bin/namingo-cron; \
    ln -s /usr/local/lib/namingo/cert-sync.sh /usr/local/bin/namingo-cert-sync

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/namingo-entrypoint"]
CMD ["php-fpm", "-F"]

FROM caddy:2.10-alpine AS web

LABEL org.opencontainers.image.title="Namingo Registry Web Gateway" \
      org.opencontainers.image.source="https://github.com/getnamingo/registry" \
      org.opencontainers.image.licenses="MIT"

COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY cp/public /var/www/cp/public
COPY whois/web /var/www/whois
