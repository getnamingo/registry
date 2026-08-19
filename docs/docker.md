# Namingo Registry with Docker Compose

This deployment runs the complete Namingo application stack without modifying
the existing Namingo PHP or SQL sources. It uses the production-recommended
MariaDB backend and starts:

- Control panel (PHP-FPM behind Caddy)
- EPP server on TCP 700
- RDAP server behind Caddy
- WHOIS server on TCP 43 and web WHOIS behind Caddy
- DAS server on TCP 1043
- Redis-backed message producer and worker
- Namingo's automation scheduler
- MariaDB, Redis, Caddy, and automatic EPP certificate synchronization

MariaDB and Redis are not published to the host. Persistent Docker volumes hold
the databases, Redis queue/session data, web certificate state, application
logs, panel resources/cache, generated zones, escrow deposits, and reports.

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

## One-command installation

After this Docker deployment is present on the repository's default branch:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/getnamingo/registry/main/docker-install.sh)
```

The bootstrap script clones the repository and launches the interactive
installer. It does not alter the host package manager or firewall.

From an existing checkout, the command is simply:

```bash
./namingo install
```

The installer asks for the base domain, administrator email/password, and TLS
mode. It then:

1. creates `.env`;
2. generates Docker secret files with mode `0600`;
3. creates a short-lived bootstrap certificate for EPP;
4. validates the Compose model;
5. builds the shared PHP runtime and web gateway images;
6. creates and imports all three Namingo databases;
7. creates the control-panel administrator idempotently;
8. neutralizes the untouched public demonstration credentials from the
   upstream seed data; and
9. starts the services and waits for their health checks.

Generated database, panel, message-token, and CAPTCHA secrets are not placed on
a command line or stored in `.env`. They live under `docker/secrets/`, which is
ignored by Git. The temporary EPP key lives under `docker/certs/`, also ignored
by Git. Optional third-party mail/payment credentials configured in `.env`
should be protected with the same host-level access controls.

### Non-interactive installation

CI or automated provisioning can supply the initial values as environment
variables. Do not put the password directly in a shell history.

```bash
export NAMINGO_DOMAIN=registry.example
export NAMINGO_ADMIN_EMAIL=admin@registry.example
export NAMINGO_TLS_MODE=public
read -r -s NAMINGO_PANEL_PASSWORD
export NAMINGO_PANEL_PASSWORD
./namingo install
unset NAMINGO_PANEL_PASSWORD
```

When stdin is non-interactive and no password is supplied, the installer
generates a random password and displays it once.

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

## Architecture note

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

## Updating

First update the Git checkout using the release/tag policy appropriate for the
registry. Review sequential Namingo database migrations in `docs/upgrade.md`.
Then rebuild and reconcile containers:

```bash
./namingo backup
git pull --ff-only
# Run every required Namingo migration in order.
./namingo update
./namingo doctor
```

The Docker wrapper deliberately does not run Git pulls or database migrations
implicitly. Registry upgrades require an explicit backup and sequential schema
review.

## PostgreSQL

The shared PHP image includes PDO PostgreSQL support, but the one-command
Compose deployment uses MariaDB because Namingo documents it as the production
backend and its single SQL import creates the registry, transaction, and audit
databases together. An external PostgreSQL deployment remains an advanced
configuration using the upstream PostgreSQL schemas and migration guidance.
