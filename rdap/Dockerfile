FROM phpswoole/swoole:php8.3-alpine

WORKDIR /opt/registry/rdap

COPY . .

RUN composer install

RUN mv /opt/registry/rdap/config.php.dist /opt/registry/rdap/config.php
RUN sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/rdap/config.php
RUN sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/rdap/config.php

VOLUME /var/log/namingo

CMD ["php","/opt/registry/rdap/start_rdap.php"]