# Namingo Registry: Docker

## Requirements

- A Linux host with Docker Engine and Docker Compose v2
- Git, OpenSSL, and a readable system CA certificate bundle
- 2 GB RAM and 10 GB disk minimum; 4 CPU, 8 GB RAM, and 50 GB disk recommended
- For public TLS, DNS records pointing at the Docker host for:
  - `cp.example.com`
  - `rdap.example.com`
  - `whois.example.com`
  - `epp.example.com`
- Host ports 80/tcp, 443/tcp+udp, 700/tcp, 43/tcp, and 1043/tcp available

The authoritative DNS hidden primary/secondaries remain an operator topology
decision. Namingo generates validated BIND-format zone files in the persistent
`zones` volume, but this Compose stack does not pretend that one bundled DNS
container is a production TLD DNS deployment. Follow `docs/dns.md` to connect
the volume/output to BIND, Knot, or Cascade and to arrange independent public
secondaries.

## Installation

### Ubuntu

Before running `docker-install.sh`, make sure Docker is installed.

```bash
apt update
apt install -y ca-certificates curl

install -m 0755 -d /etc/apt/keyrings

curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

tee /etc/apt/sources.list.d/docker.sources > /dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

apt update

apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

systemctl enable --now docker
```

You can then run:

```bash
./docker-install.sh
```

### Debian

Before running `docker-install.sh`, make sure Docker is installed.

```bash
apt update
apt install -y ca-certificates curl

install -m 0755 -d /etc/apt/keyrings

curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

tee /etc/apt/sources.list.d/docker.sources > /dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

apt update

apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

systemctl enable --now docker
```

You can then run:

```bash
./docker-install.sh
```

### FreeBSD

Docker Engine does not currently support FreeBSD as a native host.

To use the Docker installation of Namingo Registry on FreeBSD, run a supported Linux distribution such as Debian or Ubuntu inside a virtual machine and install Docker there.

The `docker-install.sh` script should then be executed inside the Linux virtual machine.

## TLS modes

`public` is the production mode. Caddy obtains certificates through ACME. The
four public names must resolve to the host and ports 80/443 must be reachable.
Caddy retries automatically if DNS propagation is incomplete.

`internal` uses Caddy's local CA and is intended for local evaluation. Browsers
and API clients will not trust that CA until its root certificate is installed.
The four hostnames must still resolve to the Docker host, either through local
hosts-file entries or test DNS. After Caddy starts, the root is copied to:

```text
docker/certs/caddy-local-root.crt
```

EPP starts immediately with the generated bootstrap certificate. The
`cert-sync` service watches Caddy's certificate store. When a valid matching
certificate appears or renews, it atomically replaces the EPP certificate; the
EPP supervisor notices and restarts only the EPP process.

To use a separately issued EPP certificate, set
`NAMINGO_CERT_SYNC_ENABLED=false` in `.env`, replace
`docker/certs/epp.crt` and `docker/certs/epp.key` with a matching PEM pair, and
restart EPP:

```bash
./namingo restart epp
```

## Day-to-day commands

```bash
./namingo status
./namingo doctor
./namingo logs
./namingo logs epp
./namingo restart rdap
./namingo shell panel
./namingo backup
./namingo down
./namingo up
```

`./namingo down` does not remove persistent data. There is intentionally no
shortcut that deletes volumes. Take and verify a backup before ever running
`docker compose down --volumes` manually.

Backups are written below `backups/<UTC timestamp>/` and contain:

- a consistent SQL dump of `registry`, `registryTransaction`, and
  `registryAudit`;
- generated zones, logs, escrow/reporting data, and panel resource
  customizations;
- a point-in-time Redis queue/session snapshot;
- Caddy ACME account/certificate and internal-CA state; and
- `.env`, Docker secrets, and EPP certificate material.

The backup directory contains production credentials and must be encrypted and
copied off-host according to the operator's recovery policy.

## Configuration

Edit `.env` for Docker and common Namingo settings, then reconcile the stack:

```bash
./namingo up
```

Advanced component-specific settings live in additive override files:

```text
docker/config/epp.override.php
docker/config/rdap.override.php
docker/config/whois.override.php
docker/config/das.override.php
docker/config/web-whois.override.php
docker/config/automation.override.php
```

These files return PHP arrays and are merged with the upstream `.dist` files at
container startup. Rebuild after changing an override:

```bash
./namingo update
```

Docker-managed database, listener, storage, and certificate paths override
conflicting values so a component cannot accidentally point back to
`localhost` for MariaDB or write outside its persistent volume.

The control panel remains the normal place to configure registry identity,
TLDs, pricing, registrars, contacts, and policies. The initial `.test` and
`.com.test` records are retained to match the upstream first-steps workflow,
but their public demonstration credentials are disabled during initialization.
Follow `docs/iog.md` before production use.

### Mail and message queue

Set `MAIL_DRIVER` and the corresponding `MAIL_*` values in `.env`.
`NAMINGO_MESSAGE_MAILER` selects `phpmailer`, `sendgrid`, or `mailgun` for the
queue worker; the `NAMINGO_SMS_*` values configure its SMS provider. The
internal message API is bound only to `127.0.0.1` inside the shared application
network namespace and is protected by a generated bearer token. Redis is
likewise loopback-only and is not published to the host.

### Registrar client certificates

Place the registrar CA bundle at `docker/certs/registrar-ca.pem`, set
`ssl_client_ca` to `/certs/registrar-ca.pem` in
`docker/config/epp.override.php`, and only then set
`NAMINGO_EPP_REQUIRE_CLIENT_CERT=true`. Test certificate rollover and client
validation before enabling it for active registrars.

## Architecture

Several upstream components intentionally communicate through hard-coded
loopback endpoints:

- applications to Redis on `127.0.0.1:6379`;
- applications to the message producer on `127.0.0.1:8250`;
- Caddy to RDAP on `127.0.0.1:7500`; and
- Caddy to PHP-FPM on `127.0.0.1:9000`.

The Compose services therefore share the `runtime` service's network namespace
while remaining separate containers with independent process supervision and
health state. This preserves upstream behavior without brittle source rewrites
or per-container TCP proxy processes. Each application entrypoint maps the four
public names to shared loopback in its own container hosts file; this avoids the
Docker Engine restriction on combining container-network mode with
engine-level host mappings. MariaDB remains a distinct service on an
internal-only Docker network.

## Upgrade

```bash
./namingo backup
git pull --ff-only
# Run every required Namingo migration in order.
./namingo update
./namingo doctor
```

Upgrading an existing Namingo Registry Docker installation is currently not supported.

See [issue #249](https://github.com/getnamingo/registry/issues/249) for more information.

If you need help upgrading or migrating an existing installation, please contact us or open a GitHub issue.

## PostgreSQL

The shared PHP image includes PDO PostgreSQL support, but the one-command
Compose deployment uses MariaDB because Namingo documents it as the production
backend and its single SQL import creates the registry, transaction, and audit
databases together. An external PostgreSQL deployment remains an advanced
configuration using the upstream PostgreSQL schemas and migration guidance.