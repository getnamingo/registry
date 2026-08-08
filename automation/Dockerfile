FROM phpswoole/swoole:php8.3-alpine

RUN apk add gmp
RUN apk add gmp-dev

RUN docker-php-ext-install gmp
RUN docker-php-ext-install mysqli

RUN apk del gmp-dev

WORKDIR /opt/registry/automation

COPY . .

RUN composer install

RUN mv /opt/registry/automation/config.php.dist /opt/registry/automation/config.php
RUN sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/automation/config.php
RUN sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/automation/config.php

VOLUME /var/log/namingo

RUN echo -e "*\t*\t*\t*\t*\t/usr/bin/php /opt/registry/automation/cron.php 1>> /dev/null 2>&1" >> /var/spool/cron/crontabs/root

CMD ["crond"]