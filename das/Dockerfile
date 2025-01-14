FROM phpswoole/swoole:php8.3-alpine

WORKDIR /opt/registry/das

COPY . .

RUN mv /opt/registry/das/config.php.dist /opt/registry/das/config.php
RUN sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/das/config.php
RUN sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/das/config.php

RUN composer install

VOLUME /var/log/namingo

CMD ["php","/opt/registry/das/start_das.php"]