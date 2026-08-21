#!/bin/sh

# Namingo Registry installer for FreeBSD 15.1-RELEASE.
#
# This is a FreeBSD-native counterpart to docs/install.sh. It uses pkg,
# rc.d, sysrc, PF, FreeBSD filesystem paths, and the official FreeBSD
# package repository's latest branch (required for PHP 8.5 + Swoole).
#
# Run on a fresh server as root. Interactive input is read from /dev/tty, so
# the script is safe to invoke through a pipe, for example:
#
#   fetch -o - https://raw.githubusercontent.com/getnamingo/registry/refs/heads/main/docs/install-freebsd.sh | sh
#
# Optional environment variables for unattended provisioning:
#   NAMINGO_DOMAIN
#   NAMINGO_IPV4
#   NAMINGO_IPV6
#   NAMINGO_INSTALL_WHOIS       yes|no (default: yes)
#   NAMINGO_DB_TYPE             M|P
#   NAMINGO_PANEL_EMAIL
#   NAMINGO_PANEL_PASSWORD
#   NAMINGO_SSH_PORT
#   NAMINGO_CONFIGURE_FIREWALL  yes|no (default: yes)
#   NAMINGO_REGISTRY_VERSION    git tag/branch (default: v1.0.32)

set -eu

PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH
LC_ALL=C
export LC_ALL
umask 027

TARGET_FREEBSD_VERSION="15.1-RELEASE"
REGISTRY_VERSION="${NAMINGO_REGISTRY_VERSION:-v1.0.32}"
REGISTRY_ROOT="/opt/registry"
CP_ROOT="/var/www/cp"
WHOIS_WEB_ROOT="/var/www/whois"
NAMINGO_LOG_DIR="/var/log/namingo"
PHP_INI_FILE="/usr/local/etc/php/99-namingo.ini"
COMPOSER_BIN="/usr/local/bin/composer"
TMP_DIR=""
TTY_ECHO_DISABLED=0

say() {
    printf '%s\n' "$*"
}

warn() {
    printf 'Warning: %s\n' "$*" >&2
}

die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    if [ "$TTY_ECHO_DISABLED" -eq 1 ] && [ -c /dev/tty ]; then
        stty echo < /dev/tty 2>/dev/null || true
        TTY_ECHO_DISABLED=0
    fi

    case "$TMP_DIR" in
        /tmp/namingo-install.*)
            [ ! -d "$TMP_DIR" ] || rm -rf "$TMP_DIR"
            ;;
    esac
}

trap cleanup EXIT
trap 'exit 1' HUP INT TERM

prompt_for_input() {
    prompt_text=$1
    response=""
    [ -c /dev/tty ] || die "Interactive input requires /dev/tty. Set the NAMINGO_* environment variables instead."
    printf '%s: ' "$prompt_text" > /dev/tty
    IFS= read -r response < /dev/tty || die "Unable to read input."
    printf '%s' "$response"
}

prompt_for_password() {
    prompt_text=$1
    password=""
    [ -c /dev/tty ] || die "Interactive password input requires /dev/tty. Set NAMINGO_PANEL_PASSWORD instead."
    printf '%s: ' "$prompt_text" > /dev/tty
    stty -echo < /dev/tty
    TTY_ECHO_DISABLED=1
    IFS= read -r password < /dev/tty || {
        stty echo < /dev/tty 2>/dev/null || true
        TTY_ECHO_DISABLED=0
        die "Unable to read password."
    }
    stty echo < /dev/tty
    TTY_ECHO_DISABLED=0
    printf '\n' > /dev/tty
    printf '%s' "$password"
}

is_yes() {
    case "$1" in
        ""|y|Y|yes|YES|Yes) return 0 ;;
        *) return 1 ;;
    esac
}

valid_yes_no() {
    case "$1" in
        ""|y|Y|yes|YES|Yes|n|N|no|NO|No) return 0 ;;
        *) return 1 ;;
    esac
}

valid_domain() {
    printf '%s\n' "$1" | awk -F. '
        BEGIN { valid = 1 }
        NF < 2 { valid = 0; exit }
        {
            for (i = 1; i <= NF; i++) {
                if (length($i) < 1 || length($i) > 63) { valid = 0; exit }
                if ($i !~ /^[A-Za-z0-9][A-Za-z0-9-]*[A-Za-z0-9]$/ && $i !~ /^[A-Za-z0-9]$/) { valid = 0; exit }
            }
            if (length($0) > 253) { valid = 0; exit }
        }
        END { exit (valid && NR == 1) ? 0 : 1 }
    '
}

valid_ipv4() {
    printf '%s\n' "$1" | awk -F. '
        BEGIN { valid = 1 }
        NF != 4 { valid = 0; exit }
        {
            for (i = 1; i <= 4; i++) {
                if ($i !~ /^[0-9]+$/ || $i < 0 || $i > 255) { valid = 0; exit }
            }
        }
        END { exit (valid && NR == 1) ? 0 : 1 }
    '
}

valid_ipv6_syntax() {
    [ -z "$1" ] && return 0
    printf '%s\n' "$1" | grep -Eq '^[0-9A-Fa-f:.]+$' && printf '%s\n' "$1" | grep -q ':'
}

valid_email_syntax() {
    printf '%s\n' "$1" | grep -Eq '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$'
}

valid_port() {
    case "$1" in
        ""|*[!0-9]*) return 1 ;;
    esac
    [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

generate_db_username() {
    printf 'nmg_%s' "$(openssl rand -hex 4)"
}

generate_password() {
    openssl rand -base64 24 | tr -d '\n' | tr '+/' '-_'
}

service_is_running() {
    service "$1" onestatus >/dev/null 2>&1
}

start_or_restart_service() {
    service_name=$1
    if service_is_running "$service_name"; then
        service "$service_name" restart
    else
        service "$service_name" start
    fi
}

install_composer_dependencies() {
    component_dir=$1
    say "Installing Composer dependencies in ${component_dir}."
    (
        cd "$component_dir"
        COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install \
            --no-interaction \
            --no-progress \
            --prefer-dist \
            --optimize-autoloader
    )
}

configure_php_component() {
    config_file=$1
    replace_literal "$config_file" "'db_host' => 'localhost'" "'db_host' => '127.0.0.1'"
    replace_literal "$config_file" "'db_username' => 'your_username'" "'db_username' => '$DB_USER'"
    replace_literal "$config_file" "'db_password' => 'your_password'" "'db_password' => '$DB_PASSWORD'"
    replace_literal "$config_file" "'db_type' => 'mysql'" "'db_type' => '$DB_DRIVER'"
    replace_literal "$config_file" "'db_port' => 3306" "'db_port' => $DB_PORT"
}

replace_literal() {
    target_file=$1
    old_text=$2
    new_text=$3

    /usr/local/bin/php -r '
        $file = $argv[1];
        $old = $argv[2];
        $new = $argv[3];
        $contents = file_get_contents($file);
        if ($contents === false) {
            fwrite(STDERR, "Unable to read {$file}.\n");
            exit(1);
        }
        if (!str_contains($contents, $old)) {
            fwrite(STDERR, "Expected source text was not found in {$file}; the selected Registry version is incompatible with this installer.\n");
            exit(1);
        }
        $contents = str_replace($old, $new, $contents);
        if (file_put_contents($file, $contents) === false) {
            fwrite(STDERR, "Unable to update {$file}.\n");
            exit(1);
        }
    ' -- "$target_file" "$old_text" "$new_text"
}

install_namingo_rc_service() {
    service_name=$1
    working_directory=$2
    entrypoint=$3
    application_pidfile=$4
    extra_required_file=${5:-}
    rc_file="/usr/local/etc/rc.d/${service_name}"
    service_log="${NAMINGO_LOG_DIR}/${service_name}-service.log"

    cat > "$rc_file" <<EOF
#!/bin/sh

# PROVIDE: ${service_name}
# REQUIRE: LOGIN ${DB_RC_REQUIRE} redis
# KEYWORD: shutdown

. /etc/rc.subr

name="${service_name}"
rcvar="${service_name}_enable"

load_rc_config "\$name"
: \${${service_name}_enable:=NO}

pidfile="/var/run/${service_name}.supervisor.pid"
child_pidfile="/var/run/${service_name}.child.pid"
application_pidfile="${application_pidfile}"
command="/usr/sbin/daemon"
procname="/usr/sbin/daemon"
command_args="-f -H -R 3 -P \${pidfile} -p \${child_pidfile} -o ${service_log} -T ${service_name} /usr/local/bin/php ${working_directory}/${entrypoint}"
required_files="${working_directory}/${entrypoint} ${working_directory}/config.php${extra_required_file:+ ${extra_required_file}}"
${service_name}_chdir="${working_directory}"
${service_name}_env="PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin"

start_precmd="${service_name}_prestart"
extra_commands="reload"
reload_cmd="${service_name}_reload"

${service_name}_prestart()
{
    if [ -f "\${application_pidfile}" ]; then
        app_pid=\$(head -n 1 "\${application_pidfile}" 2>/dev/null || true)
        if [ -z "\${app_pid}" ] || ! kill -0 "\${app_pid}" 2>/dev/null; then
            rm -f "\${application_pidfile}"
        fi
    fi
}

${service_name}_reload()
{
    app_pid=\$(head -n 1 "\${child_pidfile}" 2>/dev/null || true)
    if [ -z "\${app_pid}" ] || ! kill -0 "\${app_pid}" 2>/dev/null; then
        echo "${service_name} child process is not running."
        return 1
    fi
    kill -HUP "\${app_pid}"
}

run_rc_command "\$1"
EOF

    chmod 0555 "$rc_file"
    sysrc "${service_name}_enable=YES" >/dev/null
}

configure_pf_firewall() {
    pf_backup=""
    pf_tcp_ports="$SSH_PORT, 80, 443, 700, 53"
    if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
        pf_tcp_ports="$pf_tcp_ports, 43, 1043"
    fi

    if [ -s /etc/pf.conf ]; then
        pf_backup="/etc/pf.conf.namingo-backup.$(date -u +%Y%m%d%H%M%S)"
        cp -p /etc/pf.conf "$pf_backup"
        say "Existing PF rules saved to ${pf_backup}."
    fi

    cat > /etc/pf.conf <<EOF
# Generated by the Namingo Registry FreeBSD installer.
set block-policy drop
set skip on lo0

block in all
pass out all keep state

# ICMP is required for diagnostics, PMTU discovery, and IPv6 operation.
pass in inet proto icmp all keep state
pass in inet6 proto icmp6 all keep state

# SSH, web, EPP, DNS, and optional WHOIS/DAS.
pass in proto tcp from any to any port { ${pf_tcp_ports} } keep state
pass in proto udp from any to any port { 53, 443 } keep state
EOF

    pfctl -nf /etc/pf.conf
    sysrc pf_enable=YES pf_rules=/etc/pf.conf >/dev/null
    if service_is_running pf; then
        service pf reload
    else
        service pf start
    fi
}

if [ "$(uname -s)" != "FreeBSD" ]; then
    die "This installer runs only on FreeBSD."
fi

if [ "$(id -u)" -ne 0 ]; then
    die "This installer must be run as root."
fi

if [ "$(sysctl -n security.jail.jailed 2>/dev/null || printf '0')" -ne 0 ]; then
    die "This installer requires a FreeBSD host or VM, not a jail."
fi

FREEBSD_VERSION=$(freebsd-version -u 2>/dev/null || uname -r)
case "$FREEBSD_VERSION" in
    "${TARGET_FREEBSD_VERSION}"|"${TARGET_FREEBSD_VERSION}-p"*) ;;
    *) die "Unsupported FreeBSD version: ${FREEBSD_VERSION}. Required: ${TARGET_FREEBSD_VERSION} (including patch levels)." ;;
esac

MACHINE_ARCH=$(uname -m)
case "$MACHINE_ARCH" in
    amd64|arm64|aarch64) ;;
    *) die "Unsupported architecture: ${MACHINE_ARCH}. PHP Swoole packages are required; use amd64 or arm64." ;;
esac

case "$REGISTRY_VERSION" in
    ""|-*|*[!A-Za-z0-9._/-]*) die "Invalid Registry tag or branch: ${REGISTRY_VERSION}" ;;
esac

MIN_RAM_MB=2000
MIN_DISK_GB=10
AVAILABLE_RAM_MB=$(sysctl -n hw.physmem | awk '{ printf "%d\n", $1 / 1024 / 1024 }')
AVAILABLE_DISK_GB=$(df -Pk / | awk 'NR == 2 { printf "%d\n", $4 / 1024 / 1024 }')

[ "$AVAILABLE_RAM_MB" -ge "$MIN_RAM_MB" ] || die "At least 2 GB RAM is required; ${AVAILABLE_RAM_MB} MB detected."
[ "$AVAILABLE_DISK_GB" -ge "$MIN_DISK_GB" ] || die "At least 10 GB free disk is required; ${AVAILABLE_DISK_GB} GB detected."

for existing_path in "$REGISTRY_ROOT" "$CP_ROOT" "$WHOIS_WEB_ROOT"; do
    [ ! -e "$existing_path" ] || die "${existing_path} already exists. This installer requires a fresh Namingo installation."
done

say "FreeBSD ${FREEBSD_VERSION} on ${MACHINE_ARCH} meets the minimum requirements."

REGISTRY_DOMAIN=${NAMINGO_DOMAIN:-}
[ -n "$REGISTRY_DOMAIN" ] || REGISTRY_DOMAIN=$(prompt_for_input "Enter main domain for registry")
REGISTRY_DOMAIN=$(printf '%s' "$REGISTRY_DOMAIN" | tr '[:upper:]' '[:lower:]')
valid_domain "$REGISTRY_DOMAIN" || die "Invalid registry domain: ${REGISTRY_DOMAIN}"

YOUR_IPV4_ADDRESS=${NAMINGO_IPV4:-}
[ -n "$YOUR_IPV4_ADDRESS" ] || YOUR_IPV4_ADDRESS=$(prompt_for_input "Enter the IPv4 address Caddy should bind")
valid_ipv4 "$YOUR_IPV4_ADDRESS" || die "Invalid IPv4 address: ${YOUR_IPV4_ADDRESS}"

YOUR_IPV6_ADDRESS=${NAMINGO_IPV6:-}
if [ -z "$YOUR_IPV6_ADDRESS" ] && [ -z "${NAMINGO_IPV6+x}" ]; then
    YOUR_IPV6_ADDRESS=$(prompt_for_input "Enter the IPv6 address Caddy should bind (leave blank if unavailable)")
fi
valid_ipv6_syntax "$YOUR_IPV6_ADDRESS" || die "Invalid IPv6 address syntax: ${YOUR_IPV6_ADDRESS}"

WHOIS_CHOICE=${NAMINGO_INSTALL_WHOIS:-}
[ -n "$WHOIS_CHOICE" ] || WHOIS_CHOICE=$(prompt_for_input "Install optional WHOIS/DAS servers on TCP 43/1043? [Y/n]")
valid_yes_no "$WHOIS_CHOICE" || die "Invalid WHOIS choice. Use yes or no."
if is_yes "$WHOIS_CHOICE"; then
    INSTALL_WHOIS_SERVER=yes
else
    INSTALL_WHOIS_SERVER=no
fi

DB_TYPE_INPUT=${NAMINGO_DB_TYPE:-}
[ -n "$DB_TYPE_INPUT" ] || DB_TYPE_INPUT=$(prompt_for_input "Enter database type [M = MariaDB 11.8, P = PostgreSQL 18]")
DB_TYPE_INPUT=$(printf '%s' "$DB_TYPE_INPUT" | tr '[:lower:]' '[:upper:]')
case "$DB_TYPE_INPUT" in
    M)
        DB_TYPE=mariadb
        DB_DRIVER=mysql
        DB_PORT=3306
        DB_RC_REQUIRE=mysql
        ;;
    P)
        DB_TYPE=pgsql
        DB_DRIVER=pgsql
        DB_PORT=5432
        DB_RC_REQUIRE=postgresql
        ;;
    *) die "Invalid database type. Use M or P." ;;
esac

PANEL_EMAIL=${NAMINGO_PANEL_EMAIL:-}
[ -n "$PANEL_EMAIL" ] || PANEL_EMAIL=$(prompt_for_input "Enter panel admin email")
valid_email_syntax "$PANEL_EMAIL" || die "Invalid panel admin email syntax."

PANEL_PASSWORD=${NAMINGO_PANEL_PASSWORD:-}
[ -n "$PANEL_PASSWORD" ] || PANEL_PASSWORD=$(prompt_for_password "Enter panel admin password")
[ -n "$PANEL_PASSWORD" ] || die "Panel admin password cannot be empty."

DETECTED_SSH_PORT=22
if [ -n "${SSH_CONNECTION:-}" ]; then
    detected_port=$(printf '%s\n' "$SSH_CONNECTION" | awk '{ print $4 }')
    if valid_port "$detected_port"; then
        DETECTED_SSH_PORT=$detected_port
    fi
fi
SSH_PORT=${NAMINGO_SSH_PORT:-}
if [ -z "$SSH_PORT" ]; then
    ssh_port_input=$(prompt_for_input "Enter SSH server port [${DETECTED_SSH_PORT}]")
    SSH_PORT=${ssh_port_input:-$DETECTED_SSH_PORT}
fi
valid_port "$SSH_PORT" || die "Invalid SSH port: ${SSH_PORT}"

FIREWALL_CHOICE=${NAMINGO_CONFIGURE_FIREWALL:-yes}
valid_yes_no "$FIREWALL_CHOICE" || die "Invalid firewall choice. Use yes or no."
if is_yes "$FIREWALL_CHOICE"; then
    CONFIGURE_FIREWALL=yes
else
    CONFIGURE_FIREWALL=no
fi

DB_USER=$(generate_db_username)
DB_PASSWORD=$(generate_password)
printf '%s\n' "$DB_USER" | grep -Eq '^nmg_[0-9a-f]{8}$' \
    || die "Unable to generate a database username."
[ "${#DB_PASSWORD}" -ge 24 ] || die "Unable to generate a database password."
PHP_MEMORY_MB=$((AVAILABLE_RAM_MB / 2))
PHP_MEMORY_LIMIT="${PHP_MEMORY_MB}M"

say "Generated database username: ${DB_USER}"
say "Generated database password: ${DB_PASSWORD}"
say "Store these credentials securely. A root-only copy will be written after installation."

TMP_DIR=$(mktemp -d /tmp/namingo-install.XXXXXX)

say "Configuring the official FreeBSD latest package branch."
install -d -m 0755 /usr/local/etc/pkg/repos
cat > /usr/local/etc/pkg/repos/FreeBSD.conf <<'EOF'
FreeBSD: {
  url: "pkg+https://pkg.FreeBSD.org/${ABI}/latest",
  mirror_type: "srv",
  signature_type: "fingerprints",
  fingerprints: "/usr/share/keys/pkg",
  enabled: yes
}
EOF

if ! pkg -N >/dev/null 2>&1; then
    env ASSUME_ALWAYS_YES=yes pkg bootstrap -f
fi
pkg update -f

COMMON_PACKAGES="bind-tools bind920 ca_root_nss caddy curl gettext-runtime git gnupg portacl-rc pv redis sudo wget"
PHP_PACKAGES="php85 php85-extensions php85-bcmath php85-curl php85-fileinfo php85-ftp php85-gd php85-gettext php85-gmp php85-pecl-imap php85-intl php85-mbstring php85-pcntl php85-readline php85-soap php85-sockets php85-sodium php85-xml php85-zip php85-zlib php85-pecl-ds php85-pecl-gnupg php85-pecl-igbinary php85-pecl-protobuf php85-pecl-redis php85-pecl-uuid php85-swoole"

say "Installing common services and PHP 8.5 packages."
# Word splitting is intentional: these are constant package-name lists.
pkg install -y $COMMON_PACKAGES $PHP_PACKAGES

# Rebuild FreeBSD's system trust store after installing ca_root_nss. Namingo's
# PHP and Swoole services use this canonical bundle for outbound TLS.
/usr/sbin/certctl rehash
[ -r /etc/ssl/cert.pem ] || die "FreeBSD CA bundle is missing: /etc/ssl/cert.pem"

if [ "$DB_TYPE" = "mariadb" ]; then
    pkg install -y mariadb118-client mariadb118-server php85-mysqli php85-pdo_mysql
else
    pkg install -y postgresql18-client postgresql18-server php85-pdo_pgsql php85-pgsql
fi

required_commands="/usr/local/bin/caddy /usr/local/bin/curl /usr/local/bin/delv /usr/local/bin/dig /usr/local/bin/dnssec-dsfromkey /usr/local/bin/git /usr/local/bin/named-checkzone /usr/local/bin/php /usr/local/bin/sudo /usr/local/sbin/php-fpm /usr/local/sbin/rndc /usr/local/sbin/visudo"
if [ "$DB_TYPE" = "mariadb" ]; then
    required_commands="$required_commands /usr/local/bin/mariadb"
else
    required_commands="$required_commands /usr/local/bin/psql"
fi
for required_command in $required_commands; do
    [ -x "$required_command" ] || die "Required executable was not installed: ${required_command}"
done

# Keep the public web server separate from PHP-FPM and its application secrets.
# The shared group grants only the filesystem traversal needed to serve public
# assets; Caddy's ACME private keys remain inaccessible to the www account.
if ! pw groupshow caddy >/dev/null 2>&1; then
    pw groupadd caddy
fi
if ! pw usershow caddy >/dev/null 2>&1; then
    pw useradd caddy -g caddy -d /nonexistent -s /usr/sbin/nologin -c "Caddy web server"
fi
pw groupmod caddy -m caddy
pw usershow www >/dev/null 2>&1 || die "The PHP package did not create the expected www account."
if ! pw groupshow namingo-web >/dev/null 2>&1; then
    pw groupadd namingo-web
fi
pw groupmod namingo-web -m www,caddy

required_extensions="bcmath ctype curl dom ds fileinfo filter ftp gd gettext gmp gnupg iconv igbinary imap intl json mbstring openssl pcntl pdo phar posix protobuf readline redis session simplexml soap sockets sodium swoole tokenizer uuid xml xmlreader xmlwriter zip zlib"
for php_extension in $required_extensions; do
    /usr/local/bin/php -r "exit(extension_loaded('${php_extension}') ? 0 : 1);" \
        || die "Required PHP extension is not loaded: ${php_extension}"
done
/usr/local/bin/php -r "exit(extension_loaded('Zend OPcache') ? 0 : 1);" \
    || die "Required PHP extension is not loaded: Zend OPcache"
/usr/local/bin/php -r "exit(extension_loaded('pdo_${DB_DRIVER}') ? 0 : 1);" \
    || die "Required PHP database extension is not loaded: pdo_${DB_DRIVER}"

if [ -n "$YOUR_IPV6_ADDRESS" ]; then
    /usr/local/bin/php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? 1 : 0);' -- "$YOUR_IPV6_ADDRESS" \
        || die "Invalid IPv6 address: ${YOUR_IPV6_ADDRESS}"
fi

say "Setting the system timezone to UTC and enabling clock synchronization."
tzsetup -s UTC
sysrc ntpd_enable=YES ntpd_sync_on_start=YES >/dev/null
if service_is_running ntpd; then
    service ntpd restart || warn "ntpd restart failed; verify time synchronization."
else
    service ntpd start || warn "ntpd start failed; verify time synchronization."
fi

cat > "$PHP_INI_FILE" <<EOF
; Generated by the Namingo Registry FreeBSD installer.
opcache.enable = 1
opcache.enable_cli = 1
memory_limit = ${PHP_MEMORY_LIMIT}
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
expose_php = 0
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.cookie_domain =
EOF
chmod 0644 "$PHP_INI_FILE"

sysrc php_fpm_enable=YES >/dev/null
start_or_restart_service php_fpm

say "Cloning Namingo Registry ${REGISTRY_VERSION}."
git clone --branch "$REGISTRY_VERSION" --single-branch --depth 1 \
    https://github.com/getnamingo/registry "$REGISTRY_ROOT"

say "Starting and provisioning the database."
if [ "$DB_TYPE" = "mariadb" ]; then
    sysrc mysql_enable=YES >/dev/null
    start_or_restart_service mysql-server

    MARIADB_SETUP_SQL="$TMP_DIR/mariadb-setup.sql"
    cat > "$MARIADB_SETUP_SQL" <<EOF
DELETE FROM mysql.global_priv WHERE User='';
DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\\_%';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON registry.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON registryTransaction.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON registryAudit.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON registry.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON registryTransaction.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON registryAudit.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
EOF
    chmod 0600 "$MARIADB_SETUP_SQL"
    mariadb -u root < "$MARIADB_SETUP_SQL"

    MYSQL_PWD="$DB_PASSWORD" mariadb -h 127.0.0.1 -u "$DB_USER" \
        < "$REGISTRY_ROOT/database/registry.mariadb.sql"
else
    sysrc postgresql_enable=YES >/dev/null
    if [ ! -s /var/db/postgres/data18/PG_VERSION ]; then
        sysrc postgresql_initdb_flags="--encoding=UTF8 --locale=C --data-checksums --auth-local=peer --auth-host=scram-sha-256" >/dev/null
        service postgresql initdb
    fi
    start_or_restart_service postgresql

    POSTGRES_SETUP_SQL="$TMP_DIR/postgres-setup.sql"
    cat > "$POSTGRES_SETUP_SQL" <<EOF
DO \$namingo\$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        ALTER ROLE "${DB_USER}" WITH LOGIN PASSWORD '${DB_PASSWORD}';
    ELSE
        CREATE ROLE "${DB_USER}" LOGIN PASSWORD '${DB_PASSWORD}';
    END IF;
END
\$namingo\$;

SELECT 'CREATE DATABASE registry OWNER "${DB_USER}"'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'registry')\gexec
SELECT 'CREATE DATABASE "registryTransaction" OWNER "${DB_USER}"'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'registryTransaction')\gexec
SELECT 'CREATE DATABASE "registryAudit" OWNER "${DB_USER}"'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'registryAudit')\gexec

ALTER DATABASE registry OWNER TO "${DB_USER}";
ALTER DATABASE "registryTransaction" OWNER TO "${DB_USER}";
ALTER DATABASE "registryAudit" OWNER TO "${DB_USER}";
EOF
    chmod 0640 "$POSTGRES_SETUP_SQL"
    chgrp postgres "$TMP_DIR"
    chmod 0750 "$TMP_DIR"
    chown postgres:postgres "$POSTGRES_SETUP_SQL"
    su -m postgres -c "/usr/local/bin/psql -v ON_ERROR_STOP=1 -d postgres -f '$POSTGRES_SETUP_SQL'"

    PGPASSWORD="$DB_PASSWORD" /usr/local/bin/psql -v ON_ERROR_STOP=1 \
        -h 127.0.0.1 -U "$DB_USER" -d registry \
        -f "$REGISTRY_ROOT/database/registry.postgres.sql"
    PGPASSWORD="$DB_PASSWORD" /usr/local/bin/psql -v ON_ERROR_STOP=1 \
        -h 127.0.0.1 -U "$DB_USER" -d registryTransaction \
        -f "$REGISTRY_ROOT/database/registryTransaction.postgres.sql"
fi

say "Installing and verifying Composer."
curl -fsSLo "$TMP_DIR/composer-setup.php" https://getcomposer.org/installer
curl -fsSLo "$TMP_DIR/composer-installer.sig" https://composer.github.io/installer.sig
EXPECTED_SIGNATURE=$(tr -d '\r\n' < "$TMP_DIR/composer-installer.sig")
ACTUAL_SIGNATURE=$(/usr/local/bin/php -r "echo hash_file('sha384', '$TMP_DIR/composer-setup.php');")
[ "$EXPECTED_SIGNATURE" = "$ACTUAL_SIGNATURE" ] || die "Composer installer signature verification failed."
/usr/local/bin/php "$TMP_DIR/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
chmod 0755 "$COMPOSER_BIN"

say "Installing Adminer."
install -d -m 0755 /usr/local/share/adminer
curl -fsSLo /usr/local/share/adminer/adminer.php https://www.adminer.org/latest.php
chmod 0644 /usr/local/share/adminer/adminer.php

say "Installing the control panel."
install -d -m 0755 /var/www
cp -R "$REGISTRY_ROOT/cp" "$CP_ROOT"
mv "$CP_ROOT/env-sample" "$CP_ROOT/.env"
replace_literal "$CP_ROOT/.env" \
    "APP_URL=https://cp.example.com" \
    "APP_URL=https://cp.${REGISTRY_DOMAIN}"
replace_literal "$CP_ROOT/.env" \
    "APP_DOMAIN=example.com" \
    "APP_DOMAIN=${REGISTRY_DOMAIN}"
replace_literal "$CP_ROOT/.env" "DB_HOST=localhost" "DB_HOST=127.0.0.1"
replace_literal "$CP_ROOT/.env" "DB_USERNAME=root" "DB_USERNAME=${DB_USER}"
replace_literal "$CP_ROOT/.env" "DB_PASSWORD=" "DB_PASSWORD=${DB_PASSWORD}"
replace_literal "$CP_ROOT/.env" "DB_DRIVER=mysql" "DB_DRIVER=${DB_DRIVER}"
replace_literal "$CP_ROOT/.env" "DB_PORT=3306" "DB_PORT=${DB_PORT}"
install_composer_dependencies "$CP_ROOT"

say "Installing web WHOIS."
install -d -m 0755 "$WHOIS_WEB_ROOT"
cp -R "$REGISTRY_ROOT/whois/web/." "$WHOIS_WEB_ROOT/"
(
    cd "$WHOIS_WEB_ROOT"
    COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" require altcha-org/altcha:^2.1 \
        --no-interaction --no-progress --prefer-dist --optimize-autoloader
)
mv "$WHOIS_WEB_ROOT/config.php.dist" "$WHOIS_WEB_ROOT/config.php"
ALTCHA_HMAC_SECRET=$(openssl rand -hex 32)
replace_literal "$WHOIS_WEB_ROOT/config.php" \
    "'whois_url' => 'whois.example.com'" \
    "'whois_url' => 'whois.${REGISTRY_DOMAIN}'"
replace_literal "$WHOIS_WEB_ROOT/config.php" \
    "'rdap_url' => 'rdap.example.com'" \
    "'rdap_url' => 'rdap.${REGISTRY_DOMAIN}'"
replace_literal "$WHOIS_WEB_ROOT/config.php" \
    "'altcha_hmac_secret' => ''" \
    "'altcha_hmac_secret' => '${ALTCHA_HMAC_SECRET}'"
if [ "$INSTALL_WHOIS_SERVER" = "no" ]; then
    replace_literal "$WHOIS_WEB_ROOT/config.php" \
        "'disable_whois' => false" \
        "'disable_whois' => true"
fi

say "Installing core servers and automation."
if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
    install_composer_dependencies "$REGISTRY_ROOT/whois/port43"
    mv "$REGISTRY_ROOT/whois/port43/config.php.dist" "$REGISTRY_ROOT/whois/port43/config.php"
    configure_php_component "$REGISTRY_ROOT/whois/port43/config.php"

    install_composer_dependencies "$REGISTRY_ROOT/das"
    mv "$REGISTRY_ROOT/das/config.php.dist" "$REGISTRY_ROOT/das/config.php"
    configure_php_component "$REGISTRY_ROOT/das/config.php"

    if [ -z "$YOUR_IPV6_ADDRESS" ]; then
        replace_literal "$REGISTRY_ROOT/whois/port43/config.php" \
            "'whois_ipv6' => '::'" \
            "'whois_ipv6' => false"
        replace_literal "$REGISTRY_ROOT/das/config.php" \
            "'das_ipv6' => '::'" \
            "'das_ipv6' => false"
    fi
fi

install_composer_dependencies "$REGISTRY_ROOT/rdap"
mv "$REGISTRY_ROOT/rdap/config.php.dist" "$REGISTRY_ROOT/rdap/config.php"
configure_php_component "$REGISTRY_ROOT/rdap/config.php"
replace_literal "$REGISTRY_ROOT/rdap/config.php" \
    "'rdap_url' => 'https://rdap.example.com'" \
    "'rdap_url' => 'https://rdap.${REGISTRY_DOMAIN}'"

install_composer_dependencies "$REGISTRY_ROOT/epp"
mv "$REGISTRY_ROOT/epp/config.php.dist" "$REGISTRY_ROOT/epp/config.php"
configure_php_component "$REGISTRY_ROOT/epp/config.php"
replace_literal "$REGISTRY_ROOT/epp/config.php" \
    "'/etc/ssl/certs/ca-certificates.crt'" \
    "'/etc/ssl/cert.pem'"
if [ -n "$YOUR_IPV6_ADDRESS" ]; then
    EPP_IPV6_VALUE="'::'"
else
    EPP_IPV6_VALUE=false
fi
replace_literal "$REGISTRY_ROOT/epp/config.php" \
    "'epp_host' => '::', // Set to 0.0.0.0 if no IPv6 support" \
    "'epp_host' => '0.0.0.0',
    'epp_ipv6' => ${EPP_IPV6_VALUE}, // FreeBSD uses a separate IPv6 listener"

install_composer_dependencies "$REGISTRY_ROOT/automation"
mv "$REGISTRY_ROOT/automation/config.php.dist" "$REGISTRY_ROOT/automation/config.php"
configure_php_component "$REGISTRY_ROOT/automation/config.php"
replace_literal "$REGISTRY_ROOT/automation/config.php" \
    "'admin_email' => 'admin@example.com'" \
    "'admin_email' => '${PANEL_EMAIL}'"

# FreeBSD defaults IPv6 sockets to v6-only. Add a separate SSL listener instead
# of changing the system-wide setting, which would conflict with WHOIS/DAS's
# existing paired IPv4 and IPv6 listeners. Additional Swoole ports inherit the
# primary port's EPP framing and TLS settings.
EPP_SERVER_OLD="\$server = new Server(
    \$c['epp_host'],
    \$c['epp_port'],
    SWOOLE_PROCESS,
    ((\$c['epp_host'] === '::') ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP) | SWOOLE_SSL
);"
EPP_SERVER_NEW="${EPP_SERVER_OLD}
if ((\$c['epp_ipv6'] ?? false) !== false) {
    \$server->addListener(\$c['epp_ipv6'], \$c['epp_port'], SWOOLE_SOCK_TCP6 | SWOOLE_SSL);
}"
replace_literal "$REGISTRY_ROOT/epp/start_epp.php" "$EPP_SERVER_OLD" "$EPP_SERVER_NEW"

# Swoole maps tcp_defer_accept to FreeBSD's HTTP accept filter. EPP is a
# server-first protocol, so that filter would prevent the greeting from being
# sent. Disable only this Linux-oriented optimization.
replace_literal "$REGISTRY_ROOT/epp/start_epp.php" \
    "'tcp_defer_accept' => true" \
    "'tcp_defer_accept' => false"
replace_literal "$REGISTRY_ROOT/epp/start_epp.php" \
    "'/etc/ssl/certs/ca-certificates.crt'" \
    "'/etc/ssl/cert.pem'"

# msg_producer originally daemonizes itself for systemd Type=forking. Keep it
# in the foreground so FreeBSD daemon(8) can supervise and restart it.
replace_literal "$REGISTRY_ROOT/automation/msg_producer.php" \
    "'daemonize'  => true" \
    "'daemonize'  => false"

# FreeBSD package paths for optional DNS servers.
replace_literal "$REGISTRY_ROOT/automation/write-zone.php" \
    "'/var/lib/bind'" \
    "'/usr/local/etc/namedb/primary'"
replace_literal "$REGISTRY_ROOT/automation/write-zone.php" \
    "'/var/lib/knot/zones'" \
    "'/var/db/knot/zones'"
replace_literal "$REGISTRY_ROOT/automation/write-zone.php" \
    "'/var/lib/cascade/zones'" \
    "'/var/db/cascade/zones'"

SYSTEM_CONTROLLER="$CP_ROOT/app/Controllers/SystemController.php"
replace_literal "$SYSTEM_CONTROLLER" \
    "file_exists('/usr/sbin/rndc')" \
    "file_exists('/usr/local/sbin/rndc')"
replace_literal "$SYSTEM_CONTROLLER" \
    "sudo rndc dnssec -status" \
    "/usr/local/bin/sudo -n /usr/local/sbin/namingo-rndc-dnssec-status"
replace_literal "$SYSTEM_CONTROLLER" \
    "dnssec-dsfromkey -2 /var/lib/bind" \
    "/usr/local/bin/sudo -n /usr/local/sbin/namingo-dnssec-dsfromkey /usr/local/etc/namedb/primary"
replace_literal "$SYSTEM_CONTROLLER" \
    "file_exists('/usr/sbin/knotc')" \
    "file_exists('/usr/local/sbin/knotc')"
replace_literal "$SYSTEM_CONTROLLER" \
    "sudo -n keymgr" \
    "/usr/local/bin/sudo -n /usr/local/sbin/namingo-keymgr"

say "Installing restricted DNS and PHP-FPM privilege helpers."
cat > /usr/local/sbin/namingo-rndc-dnssec-status <<'EOF'
#!/bin/sh
set -eu

[ "$#" -eq 1 ] || exit 64
case "$1" in
    ""|*[!A-Za-z0-9.-]*) exit 64 ;;
esac

exec /usr/local/sbin/rndc dnssec -status "$1"
EOF
chmod 0555 /usr/local/sbin/namingo-rndc-dnssec-status

cat > /usr/local/sbin/namingo-dnssec-dsfromkey <<'EOF'
#!/bin/sh
set -eu

[ "$#" -eq 1 ] || exit 64
key_path=$1
case "$key_path" in
    /usr/local/etc/namedb/primary/K*) ;;
    *) exit 64 ;;
esac
key_name=${key_path#/usr/local/etc/namedb/primary/}
case "$key_name" in
    ""|*[!A-Za-z0-9.+-]*) exit 64 ;;
esac

exec /usr/local/bin/dnssec-dsfromkey -2 "$key_path"
EOF
chmod 0555 /usr/local/sbin/namingo-dnssec-dsfromkey

cat > /usr/local/sbin/namingo-keymgr <<'EOF'
#!/bin/sh
set -eu

[ "$#" -eq 2 ] || exit 64
case "$1" in
    ""|*[!A-Za-z0-9.-]*) exit 64 ;;
esac
case "$2" in
    list|ds) ;;
    *) exit 64 ;;
esac

exec /usr/local/sbin/keymgr "$1" "$2"
EOF
chmod 0555 /usr/local/sbin/namingo-keymgr

cat > /usr/local/sbin/namingo-service-status <<'EOF'
#!/bin/sh
set -eu

[ "$#" -eq 1 ] || exit 64
case "$1" in
    epp|whois|rdap|das|msg_producer|msg_worker|redis) ;;
    *) exit 64 ;;
esac

exec /usr/sbin/service "$1" onestatus
EOF
chmod 0555 /usr/local/sbin/namingo-service-status

install -d -m 0750 /usr/local/etc/sudoers.d
cat > /usr/local/etc/sudoers.d/namingo <<'EOF'
www ALL=(root) NOPASSWD: /usr/local/sbin/namingo-rndc-dnssec-status *, /usr/local/sbin/namingo-dnssec-dsfromkey *, /usr/local/sbin/namingo-keymgr *, /usr/local/sbin/namingo-service-status *, /usr/sbin/service php_fpm restart
EOF
chmod 0440 /usr/local/etc/sudoers.d/namingo
/usr/local/sbin/visudo -cf /usr/local/etc/sudoers.d/namingo >/dev/null

say "Applying FreeBSD control-panel compatibility."
cat > "$CP_ROOT/app/Lib/FreeBSDSystem.php" <<'EOF'
<?php

namespace App\Lib;

use RuntimeException;

final class FreeBSDSystem
{
    public static function getCPUCores(): int
    {
        return max(1, (int) trim((string) shell_exec('/sbin/sysctl -n hw.ncpu')));
    }

    public static function getCPUUsage(int $duration = 1): float
    {
        $start = self::cpuTimes();
        sleep(max(1, $duration));
        $end = self::cpuTimes();

        $totalDelta = array_sum($end) - array_sum($start);
        $idleDelta = $end[4] - $start[4];
        if ($totalDelta <= 0) {
            return 0.0;
        }

        return max(0.0, min(100.0, (($totalDelta - $idleDelta) / $totalDelta) * 100));
    }

    public static function getMemoryTotal(): int
    {
        return (int) (((int) trim((string) shell_exec('/sbin/sysctl -n hw.physmem'))) / 1024 / 1024);
    }

    public static function getMemoryFree(): int
    {
        $pages = (int) trim((string) shell_exec('/sbin/sysctl -n vm.stats.vm.v_free_count'));
        $pageSize = (int) trim((string) shell_exec('/sbin/sysctl -n hw.pagesize'));
        return (int) (($pages * $pageSize) / 1024 / 1024);
    }

    public static function getDiskTotal(string $directory = __DIR__): int
    {
        $bytes = disk_total_space($directory);
        if ($bytes === false) {
            throw new RuntimeException('Unable to get disk space.');
        }
        return (int) ($bytes / 1024 / 1024);
    }

    public static function getDiskFree(string $directory = __DIR__): int
    {
        $bytes = disk_free_space($directory);
        if ($bytes === false) {
            throw new RuntimeException('Unable to get free disk space.');
        }
        return (int) ($bytes / 1024 / 1024);
    }

    /** @return array<int, int> */
    private static function cpuTimes(): array
    {
        $raw = trim((string) shell_exec('/sbin/sysctl -n kern.cp_time'));
        $values = preg_split('/\s+/', $raw);
        if ($values === false || count($values) < 5) {
            throw new RuntimeException('Unable to read FreeBSD CPU statistics.');
        }
        return array_map('intval', array_slice($values, 0, 5));
    }
}
EOF
chmod 0644 "$CP_ROOT/app/Lib/FreeBSDSystem.php"

REPORTS_CONTROLLER="$CP_ROOT/app/Controllers/ReportsController.php"
replace_literal "$REPORTS_CONTROLLER" \
    'use Utopia\System\System;' \
    'use App\Lib\FreeBSDSystem as System;'
replace_literal "$REPORTS_CONTROLLER" \
    "\$output = @shell_exec(\"service \$serviceName status\");" \
    "\$output = []; \$status = 1; @exec(\"/usr/local/bin/sudo -n /usr/local/sbin/namingo-service-status \" . escapeshellarg(\$serviceName) . \" 2>&1\", \$output, \$status);"
replace_literal "$REPORTS_CONTROLLER" \
    "return (\$output && strpos(\$output, 'active (running)') !== false) ? 'Running' : 'Stopped';" \
    "return \$status === 0 ? 'Running' : 'Stopped';"

CLEAR_CACHE_SCRIPT="$CP_ROOT/bin/clear_cache.php"
replace_literal "$CLEAR_CACHE_SCRIPT" \
    "sudo systemctl restart php{\$version}-fpm" \
    "/usr/local/bin/sudo -n /usr/sbin/service php_fpm restart"
replace_literal "$CLEAR_CACHE_SCRIPT" \
    "sudo systemctl restart php8.5-fpm" \
    "/usr/local/bin/sudo -n /usr/sbin/service php_fpm restart"
replace_literal "$CLEAR_CACHE_SCRIPT" \
    "sudo systemctl restart php8.3-fpm" \
    "/usr/local/bin/sudo -n /usr/sbin/service php_fpm restart"
replace_literal "$CLEAR_CACHE_SCRIPT" \
    "systemctl output" \
    "service output"

install -d -m 0755 "$NAMINGO_LOG_DIR"
chown -R root:www "$NAMINGO_LOG_DIR"
chmod 2770 "$NAMINGO_LOG_DIR"
install -d -m 0750 /opt/backup

# The installer uses a restrictive umask. PHP-FPM can read the complete panel;
# Caddy can traverse the panel root and read only public assets. Both can read
# web WHOIS, except its secret-bearing configuration file.
chown -R root:www "$CP_ROOT" "$WHOIS_WEB_ROOT"
chmod -R g+rX,o-rwx "$CP_ROOT" "$WHOIS_WEB_ROOT"
chown root:namingo-web "$CP_ROOT"
chown -R root:namingo-web "$CP_ROOT/public" "$WHOIS_WEB_ROOT"
install -d -m 0750 "$CP_ROOT/cache"
chown -R www:www "$CP_ROOT/cache"
chown root:www "$CP_ROOT/.env" "$WHOIS_WEB_ROOT/config.php"
chmod 0640 "$CP_ROOT/.env" "$WHOIS_WEB_ROOT/config.php"
if ! /usr/bin/su -m caddy -c \
    "test -x '$CP_ROOT' && test -r '$CP_ROOT/public/index.php' && test -r '$WHOIS_WEB_ROOT/index.php' && ! test -r '$CP_ROOT/.env' && ! test -r '$WHOIS_WEB_ROOT/config.php'"; then
    die "Caddy web-root permissions are not isolated as expected."
fi
if ! /usr/bin/su -m www -c \
    "test -r '$CP_ROOT/.env' && test -r '$CP_ROOT/public/index.php' && test -r '$WHOIS_WEB_ROOT/config.php'"; then
    die "PHP-FPM web-root permissions are not configured correctly."
fi

component_configs="$REGISTRY_ROOT/epp/config.php $REGISTRY_ROOT/rdap/config.php $REGISTRY_ROOT/automation/config.php"
if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
    component_configs="$component_configs $REGISTRY_ROOT/whois/port43/config.php $REGISTRY_ROOT/das/config.php"
fi
for component_config in $component_configs; do
    chown root:wheel "$component_config"
    chmod 0600 "$component_config"
done

say "Installing native rc.d services."
if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
    install_namingo_rc_service whois "$REGISTRY_ROOT/whois/port43" start_whois.php /var/run/whois.pid
    install_namingo_rc_service das "$REGISTRY_ROOT/das" start_das.php /var/run/das.pid
fi
install_namingo_rc_service rdap "$REGISTRY_ROOT/rdap" start_rdap.php /var/run/rdap.pid
install_namingo_rc_service epp "$REGISTRY_ROOT/epp" start_epp.php /var/run/epp.pid \
    "$REGISTRY_ROOT/epp/epp.crt $REGISTRY_ROOT/epp/epp.key"
install_namingo_rc_service msg_producer "$REGISTRY_ROOT/automation" msg_producer.php /var/run/msg_producer.pid
install_namingo_rc_service msg_worker "$REGISTRY_ROOT/automation" msg_worker.php /var/run/msg_worker.pid

sysrc redis_enable=YES >/dev/null
start_or_restart_service redis

say "Installing Caddy and PF configuration."
if [ -n "$YOUR_IPV6_ADDRESS" ]; then
    BIND_LINE="bind ${YOUR_IPV4_ADDRESS} ${YOUR_IPV6_ADDRESS}"
else
    BIND_LINE="bind ${YOUR_IPV4_ADDRESS}"
fi

install -d -m 0755 /usr/local/etc/caddy
cat > /usr/local/etc/caddy/Caddyfile <<EOF
rdap.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    reverse_proxy 127.0.0.1:7500
    encode zstd gzip
    header -Server
    log {
        output file /var/log/caddy/namingo-rdap.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
    header * {
        Referrer-Policy "no-referrer"
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
        Access-Control-Allow-Origin "*"
        Access-Control-Allow-Methods "GET, OPTIONS"
        Access-Control-Allow-Headers "Content-Type"
    }
}

whois.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    root * ${WHOIS_WEB_ROOT}
    encode zstd gzip
    php_fastcgi 127.0.0.1:9000
    file_server
    header -Server
    log {
        output file /var/log/caddy/namingo-whois.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
    header * {
        Referrer-Policy "no-referrer"
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; connect-src 'self' https:; form-action 'self'; worker-src 'self' blob:; frame-src 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
    }
}

cp.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    root * ${CP_ROOT}/public
    php_fastcgi 127.0.0.1:9000
    encode zstd gzip
    file_server
    header -Server
    log {
        output file /var/log/caddy/namingo-cp.log {
            roll_size 10MB
            roll_keep 5
        }
        format json
    }
    route /adminer.php* {
        root * /usr/local/share/adminer
        php_fastcgi 127.0.0.1:9000
    }
    header * {
        Referrer-Policy "same-origin"
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; connect-src 'self'; img-src https: data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; worker-src 'none'; frame-src 'none';"
        Permissions-Policy "accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=()"
    }
}

epp.${REGISTRY_DOMAIN} {
    ${BIND_LINE}
    redir https://cp.${REGISTRY_DOMAIN}{uri}
}
EOF

/usr/local/bin/caddy fmt --overwrite /usr/local/etc/caddy/Caddyfile

install -d -o caddy -g caddy -m 0755 /var/db/caddy /var/log/caddy
install -d -o caddy -g caddy -m 0700 /var/db/caddy/config /var/db/caddy/data
install -d -o caddy -g caddy -m 0700 /var/run/caddy
if /usr/bin/su -m www -c 'test -r /var/db/caddy/data'; then
    die "PHP-FPM can unexpectedly read Caddy's private certificate storage."
fi
chown root:caddy /usr/local/etc/caddy/Caddyfile
chmod 0640 /usr/local/etc/caddy/Caddyfile
XDG_CONFIG_HOME=/var/db/caddy/config XDG_DATA_HOME=/var/db/caddy/data \
    /usr/bin/su -m caddy -c \
    '/usr/local/bin/caddy validate --config /usr/local/etc/caddy/Caddyfile --adapter caddyfile'

sysrc portacl_enable=YES >/dev/null
sysrc portacl_users=caddy >/dev/null
sysrc portacl_user_caddy_tcp="http https" >/dev/null
sysrc portacl_user_caddy_udp=https >/dev/null
start_or_restart_service portacl

sysrc caddy_enable=YES caddy_user=caddy caddy_group=caddy >/dev/null
chown -R caddy:caddy /var/db/caddy /var/log/caddy /var/run/caddy
start_or_restart_service caddy

if [ "$CONFIGURE_FIREWALL" = "yes" ]; then
    configure_pf_firewall
else
    if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
        firewall_whois_ports=",43,1043"
    else
        firewall_whois_ports=""
    fi
    warn "PF configuration was skipped. Open TCP ${SSH_PORT},80,443,700,53${firewall_whois_ports} and UDP 53,443 in your firewall."
fi

say "Installing issuer-independent Caddy certificate synchronization for EPP."
install -d -m 0700 /var/db/namingo
cat > /usr/local/sbin/namingo-cert-sync <<EOF
#!/bin/sh
set -eu

host='epp.${REGISTRY_DOMAIN}'
storage='/var/db/caddy/data/caddy/certificates'
destination='${REGISTRY_ROOT}/epp'
state='/var/db/namingo/epp-cert.sha256'

[ -d "\$storage" ] || exit 1

latest=''
latest_mtime=0
for candidate in \$(find "\$storage" -type f -name "\${host}.crt" -path "*/\${host}/\${host}.crt" 2>/dev/null); do
    candidate_mtime=\$(stat -f '%m' "\$candidate")
    if [ "\$candidate_mtime" -gt "\$latest_mtime" ]; then
        latest=\$candidate
        latest_mtime=\$candidate_mtime
    fi
done

[ -n "\$latest" ] || exit 1
key="\${latest%.crt}.key"
[ -s "\$latest" ] && [ -s "\$key" ] || exit 1

fingerprint="\$(sha256 -q "\$latest"):\$(sha256 -q "\$key")"
old_fingerprint=''
[ ! -f "\$state" ] || old_fingerprint=\$(cat "\$state")

changed=no
if [ "\$fingerprint" != "\$old_fingerprint" ] || [ ! -L "\$destination/epp.crt" ] || [ ! -L "\$destination/epp.key" ]; then
    ln -sfn "\$latest" "\$destination/.epp.crt.new"
    ln -sfn "\$key" "\$destination/.epp.key.new"
    mv -f "\$destination/.epp.crt.new" "\$destination/epp.crt"
    mv -f "\$destination/.epp.key.new" "\$destination/epp.key"
    printf '%s\n' "\$fingerprint" > "\$state"
    chmod 0600 "\$state"
    changed=yes
fi

if service epp enabled >/dev/null 2>&1; then
    if service epp onestatus >/dev/null 2>&1; then
        if [ "\$changed" = yes ]; then
            service epp restart
        fi
    else
        service epp start
    fi
fi
EOF
chmod 0555 /usr/local/sbin/namingo-cert-sync

cat > /usr/local/sbin/namingo-certwatch <<'EOF'
#!/bin/sh
while :; do
    /usr/local/sbin/namingo-cert-sync >/dev/null 2>&1 || true
    sleep 30
done
EOF
chmod 0555 /usr/local/sbin/namingo-certwatch

cat > /usr/local/etc/rc.d/namingo_certwatch <<'EOF'
#!/bin/sh

# PROVIDE: namingo_certwatch
# REQUIRE: LOGIN caddy
# KEYWORD: shutdown

. /etc/rc.subr

name="namingo_certwatch"
rcvar="namingo_certwatch_enable"
load_rc_config "$name"
: ${namingo_certwatch_enable:=NO}

pidfile="/var/run/namingo_certwatch.pid"
command="/usr/sbin/daemon"
procname="/usr/sbin/daemon"
command_args="-f -R 3 -P ${pidfile} -T namingo_certwatch /usr/local/sbin/namingo-certwatch"

run_rc_command "$1"
EOF
chmod 0555 /usr/local/etc/rc.d/namingo_certwatch
sysrc namingo_certwatch_enable=YES >/dev/null

say "Configuring the panel administrator and initial cache."
PANEL_EMAIL="$PANEL_EMAIL" \
PANEL_PASSWORD="$PANEL_PASSWORD" \
PANEL_USERNAME=admin \
    /usr/local/bin/php "$CP_ROOT/bin/create_admin_user.php"
chown -R www:www "$CP_ROOT/cache"

say "Downloading ICANN TMCH certificate data."
install -d -m 0755 /etc/ssl/certs
curl -fsSLo /etc/ssl/certs/tmch.pem https://ca.icann.org/tmch.crt
curl -fsSLo /etc/ssl/certs/tmch_pilot.pem https://ca.icann.org/tmch_pilot.crt
chmod 0644 /etc/ssl/certs/tmch.pem /etc/ssl/certs/tmch_pilot.pem

say "Enabling the minute-by-minute automation dispatcher."
if ! grep -q 'Namingo Registry automation' /etc/crontab; then
    cat >> /etc/crontab <<'EOF'

# Namingo Registry automation
* * * * * root PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin /usr/local/bin/php /opt/registry/automation/cron.php >/dev/null 2>&1
EOF
fi
sysrc cron_enable=YES >/dev/null
start_or_restart_service cron

say "Starting Namingo services."
if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
    start_or_restart_service whois
    start_or_restart_service das
fi
start_or_restart_service rdap
start_or_restart_service msg_producer
start_or_restart_service msg_worker

EPP_CERT_READY=no
attempt=0
while [ "$attempt" -lt 18 ]; do
    if /usr/local/sbin/namingo-cert-sync >/dev/null 2>&1; then
        EPP_CERT_READY=yes
        break
    fi
    attempt=$((attempt + 1))
    sleep 5
done

if [ "$EPP_CERT_READY" = "yes" ]; then
    start_or_restart_service epp
else
    warn "Caddy has not obtained the epp.${REGISTRY_DOMAIN} certificate yet. EPP is enabled and the certificate watcher will start it automatically when DNS and ACME validation succeed."
fi
start_or_restart_service namingo_certwatch

CREDENTIALS_FILE=/root/namingo-registry-credentials.txt
cat > "$CREDENTIALS_FILE" <<EOF
Namingo Registry installation
Installed UTC: $(date -u '+%Y-%m-%dT%H:%M:%SZ')
Registry version: ${REGISTRY_VERSION}
Database type: ${DB_TYPE}
Database host: 127.0.0.1
Database port: ${DB_PORT}
Database username: ${DB_USER}
Database password: ${DB_PASSWORD}
Panel admin email: ${PANEL_EMAIL}
EOF
chmod 0600 "$CREDENTIALS_FILE"

if ! pkg audit -F; then
    warn "pkg audit did not return a clean result. Review its output before production use."
fi

say ""
say "Namingo Registry installation completed on FreeBSD ${FREEBSD_VERSION}."
say ""
say "Access points:"
say " - Control Panel:   https://cp.${REGISTRY_DOMAIN}"
say " - RDAP:            https://rdap.${REGISTRY_DOMAIN}"
say " - WHOIS (web):     https://whois.${REGISTRY_DOMAIN}"
if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
    say " - WHOIS (port 43): whois.${REGISTRY_DOMAIN}:43"
    say " - DAS:             whois.${REGISTRY_DOMAIN}:1043"
else
    say " - WHOIS/DAS TCP:   not installed"
fi
say " - EPP endpoint:    epp.${REGISTRY_DOMAIN}:700"
say ""
say "Service checks:"
if [ "$INSTALL_WHOIS_SERVER" = "yes" ]; then
    say " service whois status; service das status"
fi
say " service rdap status; service epp status"
say " service msg_producer status; service msg_worker status"
say " service caddy status; service php_fpm status; service redis status"
say ""
say "Credentials: ${CREDENTIALS_FILE} (mode 0600)"
say "Next: review ${REGISTRY_ROOT}/automation/config.php and the Namingo configuration, DNS, payment, and first-steps guides."
