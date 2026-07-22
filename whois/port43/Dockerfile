FROM phpswoole/swoole:php8.3-alpine

WORKDIR /opt/registry/whois/port43

COPY . .

RUN composer install

RUN mv /opt/registry/whois/port43/config.php.dist /opt/registry/whois/port43/config.php
RUN sed -i "s|'db_username' => 'your_username'|'db_username' => '$DB_USER'|g" /opt/registry/whois/port43/config.php
RUN sed -i "s|'db_password' => 'your_password'|'db_password' => '$DB_PASSWORD'|g" /opt/registry/whois/port43/config.php

VOLUME /var/log/namingo

CMD ["php","/opt/registry/whois/port43/start_whois.php"]