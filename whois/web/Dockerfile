FROM php:8.3-alpine

WORKDIR /var/www/whois

COPY . .

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN composer install

RUN mv /var/www/whois/config.php.dist /var/www/whois/config.php
RUN sed -i "s|'whois_url' => '.*'|'whois_url' => 'whois.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php
RUN sed -i "s|'rdap_url' => '.*'|'rdap_url' => 'rdap.${REGISTRY_DOMAIN}'|" /var/www/whois/config.php

VOLUME /var/log/namingo

CMD ["php-fpm","-D"]