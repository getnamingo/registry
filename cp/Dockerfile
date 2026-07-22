FROM php:8.3-alpine

WORKDIR /var/www/cp

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install

VOLUME /var/log/namingo

CMD ["php-fpm","-D"]