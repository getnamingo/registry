# Namingo Registry
Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD and gTLD domain registries, brand registries, and ICANN accredited registrars. Inspired by XPanel Registry (https://github.com/XPanel/epp), Namingo incorporates certain elements and parts of the code from XPanel and has been significantly rewritten. It's a fusion of innovation and imagination, designed to be more than a tool; it's a vision of a world where names breathe, sing, and resonate with meaning.

Though we're still spreading our wings, our commitment to excellence ensures that Namingo is on an evolutionary journey. Gradually, we are expanding our capabilities, and before the launch of our first full version, support for a comprehensive range of services will be seamlessly integrated.

Fully primed to support gTLDs for the next ICANN application round, Namingo stands at the forefront of domain innovation, ready to embrace the next wave of digital transformation.

## Get Involved

We are actively looking for help and contributors to make Namingo even better! Whether you're a developer, designer, or just someone with great ideas, we would love to have you on board. Check out our issues or feel free to reach out to us directly.

## Licensing

Namingo is inspired by XPanel Registry (https://github.com/XPanel/epp), which is licensed under the Apache 2.0 License, © 2017 XPanel Ltd. Namingo incorporates certain elements and parts of the code from XPanel and has been significantly rewritten. It is independently licensed under the MIT License.

## Installation & Usage

1. Install the required packages:

```bash
add-apt-repository ppa:ondrej/php
apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt upgrade
apt install -y bzip2 caddy composer curl git gnupg2 mariadb-client mariadb-server net-tools php8.2 php8.2-bcmath php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gnupg php8.2-intl php8.2-mbstring php8.2-mysql php8.2-opcache php8.2-readline php8.2-swoole php8.2-xml unzip wget whois
```

2. Install Adminer:

```bash
mkdir /usr/share/adminer
wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php
```

3. Download Namingo:

```bash
git clone https://github.com/getnamingo/registry
```

4. Configure MariaDB:

```bash
mysql_secure_installation
```

5. Edit ```/etc/caddy/Caddyfile``` and place the following content:

```
rdap.example.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    reverse_proxy localhost:7500
    encode gzip
    file_server
    tls your-email@example.com
}

whois.example.com {
    bind YOUR_IPV4_ADDRESS YOUR_IPV6_ADDRESS
    root * /path/to/your/whois/app
    encode gzip
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    tls your-email@example.com
}

cp.example.com {
    bind NEW_IPV4_ADDRESS NEW_IPV6_ADDRESS
    root * /path/to/your/php/app/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    encode gzip
    file_server
    tls your-email@example.com
    log {
        output file /var/log/caddy/access.log
        format console
    }
    log {
        output file /var/log/caddy/error.log
        level ERROR
    }
    # Adminer Configuration
    route /adminer.php* {
        root * /usr/share/adminer
        php_fastcgi unix//run/php/php8.2-fpm.sock
    }
}
```

6. Reload Caddy:

```bash
systemctl enable caddy
systemctl restart caddy
```

## Support & Documentation


## Acknowledgements

Special thanks to XPanel Ltd for their inspirational work on XPanel Registry.