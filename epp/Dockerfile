FROM phpswoole/swoole:php8.3-alpine

WORKDIR /opt/registry/epp

COPY . .

RUN composer install
RUN mv /opt/registry/epp/config.php.dist /opt/registry/epp/config.php
RUN sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/epp/config.php
RUN sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/epp/config.php

VOLUME /var/log/namingo

CMD ["php","/opt/registry/epp/start_epp.php"]