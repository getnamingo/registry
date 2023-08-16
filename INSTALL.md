# Installation & Usage

## 1. Install the required packages:

```bash
add-apt-repository ppa:ondrej/php
apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' -o caddy-stable.gpg.key
gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg caddy-stable.gpg.key
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt update && apt upgrade
apt install -y bzip2 caddy composer curl gettext git gnupg2 net-tools php8.2 php8.2-bcmath php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-gd php8.2-gmp php8.2-gnupg php8.2-intl php8.2-mbstring php8.2-opcache php8.2-readline php8.2-swoole php8.2-xml unzip wget whois
```

## 2. Database installation (please choose one):

### 2a. Install and configure MariaDB:

```bash
apt install -y mariadb-client mariadb-server php8.2-mysql
mysql_secure_installation
```

### 2b. Install and configure PostgreSQL:

```bash
sh -c 'echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
wget -qO- https://www.postgresql.org/media/keys/ACCC4CF8.asc | tee /etc/apt/trusted.gpg.d/pgdg.asc &>/dev/null
apt update
apt install -y postgresql postgresql-client php8.2-pgsql
psql --version
```

Now you need to update PostgreSQL Admin User Password:

```bash
sudo -u postgres psql
postgres=#
postgres=# ALTER USER postgres PASSWORD 'demoPassword';
postgres=# CREATE DATABASE registry;
postgres=# \q
```

## 3. Install Adminer:

```bash
mkdir /usr/share/adminer
wget "http://www.adminer.org/latest.php" -O /usr/share/adminer/latest.php
ln -s /usr/share/adminer/latest.php /usr/share/adminer/adminer.php
```

## 4. Download Namingo:

```bash
git clone https://github.com/getnamingo/registry
```

## 5. Edit ```/etc/caddy/Caddyfile``` and place the following content:

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

## 6. Move ```registry/cp``` to ```/path/to/your/php/app```

## 7. Move ```registry/whois/web``` to ```/path/to/your/whois/app```

## 8. Configure registry

Each component in the project comes with its own configuration file. Before getting started:
1. Edit database settings to match your setup.
2. Update IP addresses as necessary.
3. Adjust certificate paths to point to the correct locations.

Once all configurations are set, initiate the application by executing:

```bash
php app.php
```

## 9. Reload Caddy:

```bash
systemctl enable caddy
systemctl restart caddy
```

## 10. RDE (Registry data escrow) configuration:

### Generate the Key Pair:

Create a configuration file, say key-config, with the following content:

```yaml
%echo Generating a default key
Key-Type: RSA
Key-Length: 2048
Subkey-Type: RSA
Subkey-Length: 2048
Name-Real: Your Name
Name-Comment: Your Comment
Name-Email: your.email@example.com
Expire-Date: 0
%no-protection
%commit
%echo done
```

Replace "Your Name", "Your Comment", and "your.email@example.com" with your details.

Use the following command to generate the key:

```bash
gpg2 --batch --generate-key key-config
```

Your GPG key pair will now be generated.

### Exporting Your Keys:

Public key:

```bash
gpg2 --armor --export your.email@example.com > publickey.asc
```

Replace `your-email@example.com` with the email address you used when generating the key.

Private key:

```bash
gpg2 --armor --export-secret-keys your.email@example.com > privatekey.asc
```

### Secure Your Private Key:

Always keep your private key secure. Do not share it. If someone gains access to your private key, they can impersonate you in cryptographic operations.

### Use in RDE deposit generation:

Please send the exported `publickey.asc` to your RDE provider, and also place the path to `privatekey.asc` in the escrow.php system as required.