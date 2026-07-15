# Namingo Registry: DNS Setup Guide

This guide walks you through configuring the core DNS setup for your Namingo-powered registry. It includes creating a hidden master DNS server, which acts as the authoritative source for all zone data. Your TLD DNS servers (public-facing) will be configured to receive updates from this hidden master.

> [!WARNING]
> This setup is **required** for proper zone publication and delegation of domains under your TLDs.

## 1. Hidden Master DNS Server Setup

This section covers how to set up your hidden master — the central source Namingo updates with zone changes. Public-facing DNS servers (e.g., slaves or Anycast nodes) will pull zones from it and serve them to the world.

> [!WARNING]
> Choose only **one** of the following DNS backends based on your environment and preferences. Do **not** install all of them.

### 1.1. BIND9 (with native DNSSEC, OpenDNSSEC, or Unsigned Zones)

BIND9 supports multiple DNSSEC modes: native signing with automatic key rollover, external signing using OpenDNSSEC, or serving unsigned zones. Choose the method that fits your operational needs.

#### 1.1.1. Installation

```bash
apt install bind9 bind9-utils bind9-doc
```

#### 1.1.2. Generate a TSIG key

Generate a TSIG key which will be used to authenticate DNS updates between the master and slave servers. **Note: replace ```test``` with your TLD.**

```bash
cd /etc/bind
tsig-keygen -a HMAC-SHA256 test.key
```

The output will be in the format that can be directly included in your BIND configuration files. It looks something like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

Copy this output for use in the configuration files of both the master and slave DNS servers. (```/etc/bind/named.conf.local```)

> [!WARNING]
> Choose one configuration from the following section that fits your setup. Do not combine them.

#### 1.1.3a. Native DNSSEC – Signed by BIND

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following DNSSEC policy:

```bash
dnssec-policy "namingo-policy" {
    keys {
        ksk lifetime P1Y algorithm ed25519;
        zsk lifetime P2M algorithm ed25519;
    };
    nsec3param iterations 0 optout false salt-length 0;
    publish-safety 7d;
    max-zone-ttl 86400;
    dnskey-ttl 3600;
    zone-propagation-delay 3600;
    parent-propagation-delay 7200;
    parent-ds-ttl 86400;
};
```

Then, add the zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    dnssec-policy "namingo-policy";
    key-directory "/var/lib/bind";
    inline-signing yes;
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

> [!TIP]
> DNSSEC private keys must be properly secured.

Set correct ownership and permissions, then restart BIND9 to apply changes:

```bash
chown -R bind:bind /var/lib/bind
chmod -R go-rwx /var/lib/bind
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

> [!IMPORTANT]
> In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

##### 1.1.3a.1. Optional: Configure BIND with PKCS#11 Support

```bash
apt install softhsm2 opensc libengine-pkcs11-openssl
```

If using SoftHSM, also initialize the token with:

```bash
softhsm2-util --init-token --slot 0 --label YourTokenLabel
```

Edit `/etc/bind/named.conf.options` and add the following:

```bash
options {
    // Existing options...
    dnssec-policy "hsm-policy";
};

dnssec-policy "hsm-policy" {
    keys {
        ksk lifetime P1Y algorithm ecdsap256sha256;
        zsk lifetime P2M algorithm ecdsap256sha256;
    };
    max-zone-ttl 86400;
    dnskey-ttl 3600;
    zone-propagation-delay 3600;
    parent-propagation-delay 7200;
    parent-ds-ttl 86400;
};
```

Then, add the zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    dnssec-policy "hsm-policy";
    inline-signing yes;
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Set the PKCS#11 provider in environment before running BIND:

```bash
export PKCS11_PROVIDER=/usr/lib/softhsm/libsofthsm2.so
```

Set correct permissions and restart BIND9 to apply changes:

```bash
chown -R bind:bind /var/lib/bind
systemctl restart bind9
```

BIND will automatically generate keys within the device when configured correctly:

```bash
rndc loadkeys your.tld
rndc signing -list your.tld
```

You can verify the keys with tools provided by your HSM vendor or via standard PKCS#11 utilities:

```bash
softhsm2-util --show-slots
```

or

```bash
pkcs11-tool --list-objects --login --token-label YourTokenLabel
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

> [!IMPORTANT]
> In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

#### 1.1.3b. External DNSSEC – Signed by OpenDNSSEC

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone.signed";
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Install OpenDNSSEC:

```bash
apt install opendnssec opendnssec-enforcer-sqlite3 opendnssec-signer softhsm2
mkdir -p /var/lib/softhsm/tokens
chown -R opendnssec:opendnssec /var/lib/softhsm/tokens
softhsm2-util --init-token --slot 0 --label OpenDNSSEC --pin 1234 --so-pin 1234
```

Update files in `/etc/opendnssec` to match your registry policy. As minimum, please enable at least Signer Threads in `/etc/opendnssec/conf.xml`, but we recommend to fully review [all the files](https://wiki.opendnssec.org/configuration/confxml/). Then run the following commands:

```bash
chown -R opendnssec:opendnssec /etc/opendnssec
ods-enforcer-db-setup
ods-enforcer policy import
rm /etc/opendnssec/prevent-startup
chown opendnssec:opendnssec /var/lib/bind/test.zone
chmod 644 /var/lib/bind/test.zone
ods-enforcer zone add -z test -p default -i /var/lib/bind/test.zone
ods-control start
```

Edit again the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Replace the value for `file` with the following filename:

```bash
...
    file "/var/lib/opendnssec/signed/test.zone.signed";
...
};
```

Use rndc to reload BIND:

```bash
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

> [!IMPORTANT]
> In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

#### 1.1.3c. Unsigned Zone

Edit the named.conf.local file:

```bash
nano /etc/bind/named.conf.local
```

Add the following zone definition:

```bash
zone "test." {
    type master;
    file "/var/lib/bind/test.zone";
    allow-transfer { key "test.key"; };
    also-notify { <slave-server-IP>; };
};
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Use rndc to reload BIND:

```bash
systemctl restart bind9
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

#### 1.1.4. Enabling Logs

Place the contents below at `/etc/bind/named.conf.default-logging` and include the file in `/etc/bind/named.conf`:

```bash
logging {
    // General logs (startup, shutdown, errors)
    channel "misc" {
        file "/var/log/named/misc.log" versions 10 size 10m;
        print-time YES;
        print-severity YES;
        print-category YES;
    };

    // Query logs (log every DNS query)
    channel "query" {
        file "/var/log/named/query.log" versions 20 size 5m;
        print-time YES;
        print-severity NO;
        print-category NO;
    };

    // Lame server logs (misconfigured DNS servers)
    channel "lame" {
        file "/var/log/named/lamers.log" versions 3 size 5m;
        print-time YES;
        print-severity YES;
        severity info;
    };

    // Security logs (e.g., unauthorized query attempts)
    channel "security" {
        file "/var/log/named/security.log" versions 5 size 10m;
        print-time YES;
        print-severity YES;
        severity dynamic;
    };

    // DNS updates (useful for dynamic zones)
    channel "update" {
        file "/var/log/named/update.log" versions 3 size 5m;
        print-time YES;
        print-severity YES;
    };

    // Resolver logs (useful for debugging recursive queries)
    channel "resolver" {
        file "/var/log/named/resolver.log" versions 5 size 5m;
        print-time YES;
        print-severity YES;
    };

    // Zone transfer logs (incoming & outgoing transfers)
    channel "xfer" {
        file "/var/log/named/xfer.log" versions 5 size 5m;
        print-time YES;
        print-severity YES;
    };

    // Assign categories to log files
    category "default" { "misc"; };
    category "queries" { "query"; };
    category "lame-servers" { "lame"; };
    category "security" { "security"; };
    category "update" { "update"; };
    category "resolver" { "resolver"; };
    category "xfer-in" { "xfer"; };
    category "xfer-out" { "xfer"; };
};
```

#### 1.1.5. Validate and Apply Configuration

After completing your configuration, check for syntax errors using `named-checkconf` and verify your zone file with `named-checkzone test /var/lib/bind/test.zone`. If everything is correct, restart BIND9 using `systemctl restart bind9`.

To confirm that your zone has been loaded successfully, run `grep named /var/log/syslog` and look for a log entry indicating the zone was loaded without errors.

You should see something like: `zone test/IN: loaded serial 2025041901` indicating success.

> [!NOTE]
> validate signed zones periodically:
> 
> `validns test. /var/cache/bind/test.zone`

> [!TIP]
> Advanced validation pipeline: https://github.com/icann/OCTO-TE-labs/tree/extended/dnssec/08-zonedelivery

### 1.2. Knot DNS (with native DNSSEC)

#### 1.2.1. Installation

```bash
apt install knot knot-dnsutils
```

#### 1.2.2. Generate a TSIG key

Generate a TSIG key which will be used to authenticate DNS updates between the master and slave servers. **Note: replace ```test``` with your TLD.**

```bash
cd /etc/knot
knotc conf-gen-key test.key hmac-sha256
```

The output will be in the format that can be directly included in your configuration files. It looks something like this:

```bash
key:
  - id: "test.key"
    algorithm: hmac-sha256
    secret: "base64-encoded-secret=="
```

Copy this output for use in the configuration files of both the master and slave DNS servers. (```/etc/knot/knot.conf```)

#### 1.2.3. Configure DNSSEC Policy

Add the DNSSEC policy to `/etc/knot/knot.conf`:

```bash
nano /etc/knot/knot.conf
```

Add the following DNSSEC policy:

```bash
policy:
  - id: "namingo-policy"
    description: "Default DNSSEC policy for TLD"
    algorithm: ed25519
    ksk-lifetime: 1y
    zsk-lifetime: 2m
    max-zone-ttl: 86400
    rrsig-lifetime: 14d
    rrsig-refresh: 7d
    dnskey-ttl: 3600
    nsec3: true
    nsec3-iterations: 0
    nsec3-salt-length: 0
```

#### 1.2.4. Add your zone

Add the zone to `/etc/knot/knot.conf`:

```bash
zone:
  - domain: "test."
    file: "/etc/knot/zones/test.zone"
    dnssec-policy: "namingo-policy"
    key-directory: "/etc/knot/keys"
    storage: "/etc/knot/zones"
    notify: <slave-server-IP>
    acl:
      - id: "test.key"
        address: <slave-server-IP>
        key: "test.key"
```

Replace ```<slave-server-IP>``` with the actual IP address of your slave server. Replace ```test``` with your TLD.

Generate the necessary DNSSEC keys for your zone using keymgr:

```bash
keymgr policy:generate test.
```

This will create the required keys in `/etc/knot/keys`. Ensure the directory permissions are secure:

```bash
chown -R knot:knot /etc/knot/keys
chmod -R 700 /etc/knot/keys
```

Reload Knot DNS and enable DNSSEC signing for the zone:

```bash
knotc reload
knotc signzone test.
```

Generate the DS record for the parent zone using `keymgr`:

```bash
keymgr ds test.
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

> [!IMPORTANT]
> In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

#### 1.2.5. Optional: Post-Signing Validation

Validate the signed zone:

```bash
kzonecheck -v test.
validns test. /etc/knot/zones/test.zone
```

> [!TIP]
> Advanced validation pipeline: https://github.com/icann/OCTO-TE-labs/tree/extended/dnssec/08-zonedelivery

### 1.3. NSD (Advanced)

NSD is a high-performance, authoritative-only name server with no support for dynamic updates, inline signing, or native DNSSEC key management. It is suitable for production environments that prioritize speed and simplicity, but it **requires external zone signing**.

> [!CAUTION]
> NSD does **not** support DNSSEC signing or key management internally. You must handle all DNSSEC operations — such as key generation, zone signing, rollover, and DS management — using external tools like `ldns-signzone`, `dnssec-signzone`, or OpenDNSSEC.

#### 1.3.1. Prerequisites

You’ll need a separate process or automation to:

- Generate KSK and ZSK keys
- Sign your zone using `ldns-signzone` or equivalent
- Update the `.signed` file before reloading NSD

Example using `ldns-signzone`:

```bash
ldns-keygen test.
ldns-keygen -k test.
ldns-signzone test.zone Ktest.+* Ztest.+*
```

This will output a signed zone file, typically named `test.zone.signed`.

#### 1.3.2. Installation

```bash
apt install nsd ldnsutils
```

#### 1.3.3. Configure NSD to Load Signed Zone

Edit `/etc/nsd/nsd.conf` and define the zone:

```bash
zone:
  name: "test."
  zonefile: "test.zone.signed"
```

Make sure the signed zone file is placed in `/etc/nsd/` or the directory you define as the zone directory. Then restart NSD:

```bash
systemctl restart nsd
```

#### 1.3.4. Automating Zone Signing

Because NSD does not sign zones automatically, you must:

- Sign zones periodically via cron or a systemd timer
- Replace the previous `.signed` file with the new one
- Reload NSD (`nsd-control reload`) after each re-signing

Example cron job:

```bash
0 */6 * * * /usr/local/bin/sign-and-publish-zone.sh
```

Configure the `Zone Writer` in Registry Automation and run it manually the first time.

```bash
php /opt/registry/automation/write-zone.php
```

Before reloading NSD, validate the signed zone:

```bash
validns test. /etc/nsd/test.zone.signed || exit 1
nsd-checkzone test. /etc/nsd/test.zone.signed || exit 1
```

> [!TIP]
> For easier DNSSEC lifecycle management, consider using **OpenDNSSEC** as the signer and **NSD** only for serving the signed zones.
>
> Advanced validation pipeline: https://github.com/icann/OCTO-TE-labs/tree/extended/dnssec/08-zonedelivery

## 2. Setting Up a Public Secondary DNS Using BIND

This section describes how to configure a regular public-facing DNS server using BIND to act as a secondary for your hidden master. It will receive zone transfers (AXFR/IXFR) and serve as the authoritative DNS for your TLDs.

### 2.1. Installation

```bash
apt update
apt install bind9 bind9-utils bind9-doc
```

### 2.2. Add the TSIG key to the BIND Configuration

Copy the TSIG key from your hidden master server. The TSIG key configuration should look like this:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret==";
};
```

Create a directory to store zone files:

```bash
mkdir /var/cache/bind/zones
```

Edit the `named.conf.local` file:

```bash
nano /etc/bind/named.conf.local
```

First, define the TSIG key at the top of the file:

```bash
key "test.key" {
    algorithm hmac-sha256;
    secret "base64-encoded-secret=="; // Replace with your actual base64-encoded key
};
```

Then, add the slave zone configuration:

```bash
zone "test." {
    type slave;
    file "/var/cache/bind/zones/test.zone";
    masters { 192.0.2.1 key "test.key"; }; // IP of the hidden master and TSIG key reference
    allow-query { any; }; // Allow queries from all IPs
    allow-transfer { none; }; // Disable zone transfers (AXFR) to others
};
```

Make sure to replace `192.0.2.1` with the IP address of your hidden master server and `base64-encoded-secret==` with the actual secret from your TSIG key.

### 2.3. Enabling Logs

Place the contents below at `/etc/bind/named.conf.default-logging` and include the file in `/etc/bind/named.conf`:

```bash
logging {
    // General logs (startup, shutdown, errors)
    channel "misc" {
        file "/var/log/named/misc.log" versions 10 size 10m;
        print-time YES;
        print-severity YES;
        print-category YES;
    };

    // Query logs (log every DNS query)
    channel "query" {
        file "/var/log/named/query.log" versions 20 size 5m;
        print-time YES;
        print-severity NO;
        print-category NO;
    };

    // Lame server logs (misconfigured DNS servers)
    channel "lame" {
        file "/var/log/named/lamers.log" versions 3 size 5m;
        print-time YES;
        print-severity YES;
        severity info;
    };

    // Security logs (e.g., unauthorized query attempts)
    channel "security" {
        file "/var/log/named/security.log" versions 5 size 10m;
        print-time YES;
        print-severity YES;
        severity dynamic;
    };

    // DNS updates (useful for dynamic zones)
    channel "update" {
        file "/var/log/named/update.log" versions 3 size 5m;
        print-time YES;
        print-severity YES;
    };

    // Resolver logs (useful for debugging recursive queries)
    channel "resolver" {
        file "/var/log/named/resolver.log" versions 5 size 5m;
        print-time YES;
        print-severity YES;
    };

    // Zone transfer logs (incoming & outgoing transfers)
    channel "xfer" {
        file "/var/log/named/xfer.log" versions 5 size 5m;
        print-time YES;
        print-severity YES;
    };

    // Assign categories to log files
    category "default" { "misc"; };
    category "queries" { "query"; };
    category "lame-servers" { "lame"; };
    category "security" { "security"; };
    category "update" { "update"; };
    category "resolver" { "resolver"; };
    category "xfer-in" { "xfer"; };
    category "xfer-out" { "xfer"; };
};
```

### 2.4. Adjusting Permissions and Ownership

Ensure BIND has permission to write to the zone file, the logs directory and that the files are owned by the BIND user:

```bash
chown bind:bind /var/cache/bind/zones
chmod 755 /var/cache/bind/zones
chown bind:bind /var/log/named
chmod 755 /var/log/named
```

### 2.5. Validate and Apply Configuration

After completing your secondary zone setup, check for syntax errors using `named-checkconf`, then restart BIND9 using `systemctl restart bind9` to apply the changes.

To verify that the zone was successfully transferred from the hidden master, check your logs with `grep 'transfer of "test."' /var/log/syslog`. You should see a log entry confirming the successful zone transfer.

## 3. Upgrading to BIND 9.20 and Enabling Offline KSK Signing

This section applies to Namingo Registry installations using BIND as the hidden
primary DNS server. It covers:

- upgrading Ubuntu 22.04 or Ubuntu 24.04 to BIND 9.20;
- upgrading Debian 12 or Debian 13 to BIND 9.20; and
- converting existing DNSSEC-enabled Namingo zones to the BIND 9.20 Offline KSK
  workflow.

> [!IMPORTANT]
> BIND's **Offline KSK** feature is not fully offline zone signing. The Zone
> Signing Key (ZSK) remains on the hidden primary and continues signing ordinary
> zone data. Only the Key Signing Key (KSK) is kept offline. The offline system
> creates a Signed Key Response (SKR) containing the pre-signed DNSKEY, CDS, and
> CDNSKEY RRsets.
>
> Offline KSK support requires BIND 9.20.2 or newer. Install the latest available
> BIND 9.20.x package rather than pinning an old patch release.

### 3.1 Before upgrading

Perform the upgrade on one DNS server at a time. Ensure that another
authoritative server remains available while the package is being upgraded.

Check the current version and configuration:

```bash
sudo named -V | head -n 1
sudo named-checkconf
sudo named-checkconf -z
sudo rndc status
```

Create a protected backup of the BIND configuration, zone files, journals,
managed DNSSEC state, and keys:

```bash
sudo -i

BACKUP="/root/bind-before-9.20-$(date +%Y%m%d-%H%M%S).tar.gz"

tar -czpf "$BACKUP" \
    /etc/bind \
    /var/lib/bind

chmod 0600 "$BACKUP"
echo "Backup created: $BACKUP"

exit
```

The archive contains private DNSSEC keys. Move it to protected storage and do
not leave unnecessary copies on the DNS server.

For a virtual machine, also create a snapshot before changing the package
repository. Restoring a snapshot is safer than downgrading BIND after the newer
version has updated DNSSEC state files.

### 3.2 Ubuntu 22.04 and Ubuntu 24.04

The standard Ubuntu repositories provide an older BIND branch. Add the ISC
stable BIND PPA, which provides BIND 9.20 packages for Ubuntu 22.04 and 24.04:

```bash
sudo apt update
sudo apt install -y software-properties-common ca-certificates

sudo add-apt-repository -y ppa:isc/bind
sudo apt update
```

Confirm that the candidate package is from the BIND 9.20 branch:

```bash
apt-cache policy bind9
```

Install or upgrade BIND and its tools:

```bash
sudo apt install -y \
    bind9 \
    bind9-utils \
    bind9-dnsutils \
    bind9-doc
```

Validate the configuration and restart BIND:

```bash
sudo named-checkconf
sudo named-checkconf -z
sudo systemctl restart named
sudo systemctl --no-pager --full status named
```

Confirm the installed version:

```bash
named -V | head -n 1
dnssec-ksr -V
```

Both commands must report BIND 9.20.x, with `dnssec-ksr` available.

### 3.3 Debian 12 and Debian 13

Install the repository prerequisites and the archive keyring:

```bash
sudo apt-get update
sudo apt-get -y install lsb-release ca-certificates curl

curl -sSLo /tmp/debsuryorg-archive-keyring.deb \
    https://packages.sury.org/debsuryorg-archive-keyring.deb

sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
rm -f /tmp/debsuryorg-archive-keyring.deb
```

Add the BIND package repository. The distribution codename is detected
automatically as `bookworm` on Debian 12 or `trixie` on Debian 13:

```bash
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/bind/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/bind.list >/dev/null

sudo apt-get update
```

Confirm that the candidate package is from the BIND 9.20 branch:

```bash
apt-cache policy bind9
```

Install or upgrade BIND and its tools:

```bash
sudo apt-get install -y \
    bind9 \
    bind9-utils \
    bind9-dnsutils \
    bind9-doc
```

Validate the configuration and restart BIND:

```bash
sudo named-checkconf
sudo named-checkconf -z
sudo systemctl restart named
sudo systemctl --no-pager --full status named
```

Confirm the installed version:

```bash
named -V | head -n 1
dnssec-ksr -V
```

### 3.4 Post-upgrade checks

Check that the hidden primary loads the existing zones and still answers
authoritatively:

```bash
sudo rndc status
sudo rndc zonestatus example.
dig @127.0.0.1 example. SOA +norecurse
dig @127.0.0.1 example. DNSKEY +dnssec +multiline
```

Replace `example.` with the actual TLD zone.

Review the service log for errors:

```bash
sudo journalctl -u named -n 100 --no-pager
```

Run the Namingo zone generator once and confirm that the generated zone can be
reloaded:

```bash
cd /opt/registry
php /opt/registry/automation/write-zone.php

sudo rndc reload example.
sudo rndc notify example.
```

Namingo continues writing the unsigned source zone to:

```text
/var/lib/bind/example.zone
```

Keep the existing zone `file` setting. Do not change it to a manually generated
`.signed` file. BIND continues to maintain the inline-signed version.

### 3.5 Offline KSK architecture

The recommended layout is:

| System | Material stored on it | Purpose |
|---|---|---|
| Hidden primary | unsigned Namingo zone, ZSK private keys, imported SKR | Generates and signs normal zone changes |
| Offline KSK system | KSK private keys and matching policy | Signs KSR files and creates SKR files |
| Public secondary servers | transferred signed zone only | Answer public authoritative queries |

The offline KSK system must not receive:

- the registry database;
- the complete zone file;
- ZSK private keys; or
- network access during signing.

A KSR contains public ZSK information and can be transported to the offline
system. The resulting SKR contains public records and signatures and can be
returned to the hidden primary.

### 3.6 DNSSEC policy

The existing Namingo DNS manual uses separate KSK and ZSK definitions, which is
required for Offline KSK. A Combined Signing Key (CSK) cannot be used.

The active policy should ultimately contain `offline-ksk yes;`:

```conf
dnssec-policy "namingo-policy" {
    keys {
        ksk lifetime P1Y algorithm ed25519;
        zsk lifetime P2M algorithm ed25519;
    };

    offline-ksk yes;

    nsec3param iterations 0 optout false salt-length 0;
    publish-safety 7d;
    max-zone-ttl 86400;
    dnskey-ttl 3600;
    zone-propagation-delay 3600;
    parent-propagation-delay 7200;
    parent-ds-ttl 86400;
};
```

The zone definition remains otherwise unchanged:

```conf
zone "example." {
    type master;
    file "/var/lib/bind/example.zone";

    dnssec-policy "namingo-policy";
    key-directory "/var/lib/bind";
    inline-signing yes;

    allow-transfer {
        key "example.key";
    };

    also-notify {
        <secondary-server-IP>;
    };
};
```

Do **not** enable `offline-ksk yes;` on the live server until the first SKR has
been created and is ready to import. Once Offline KSK is enabled, BIND stops
creating KSK signatures and rollover keys itself and expects the required data
to be present in the imported SKR.

### 3.7 Prepare a matching policy file

Create a temporary policy file for `dnssec-ksr`. This lets the SKR be prepared
before changing the live BIND configuration:

```bash
sudo install -d -m 0700 /root/namingo-offline-ksk
sudo editor /root/namingo-offline-ksk/policy.conf
```

Add:

```conf
dnssec-policy "namingo-policy" {
    keys {
        ksk lifetime P1Y algorithm ed25519;
        zsk lifetime P2M algorithm ed25519;
    };

    offline-ksk yes;

    nsec3param iterations 0 optout false salt-length 0;
    publish-safety 7d;
    max-zone-ttl 86400;
    dnskey-ttl 3600;
    zone-propagation-delay 3600;
    parent-propagation-delay 7200;
    parent-ds-ttl 86400;
};
```

The policy name, algorithms, key lifetimes, TTLs, and rollover parameters must
match the policy that will be used by the live zone.

### 3.8 Convert an existing signed zone

The following example converts the existing zone `example.`.

The initial conversion reuses the current active KSK. This avoids an immediate
DS change in the parent zone. Because that KSK previously existed on the online
server, moving it offline does not remove any historical exposure. After the
migration is stable, schedule a normal rollover to a new KSK generated only on
the offline system.

#### 3.8.1 Inspect the current zone

```bash
sudo rndc dnssec -status example.
sudo rndc signing -list example.
sudo rndc zonestatus example.

dig @127.0.0.1 example. DNSKEY +dnssec +multiline
dig example. DS +dnssec +multiline
```

Save the output with the migration records.

Identify the current KSK. A KSK DNSKEY has flag `257`:

```bash
sudo grep -lE 'DNSKEY[[:space:]]+257[[:space:]]+3[[:space:]]+' \
    /var/lib/bind/Kexample.+*.key
```

The command should identify the active KSK public key file, for example:

```text
/var/lib/bind/Kexample.+015+12345.key
```

Set its basename without the extension:

```bash
KSK_BASE="/var/lib/bind/Kexample.+015+12345"
```

Confirm its timing metadata:

```bash
sudo dnssec-settime -K /var/lib/bind -p all "$KSK_BASE"
```

Do not continue if the KSK cannot be identified unambiguously.

#### 3.8.2 Export the current KSK to protected offline storage

Create a temporary export directory:

```bash
sudo install -d -m 0700 /root/namingo-offline-ksk/example
```

Copy the public and private key files:

```bash
sudo cp -a \
    "${KSK_BASE}.key" \
    "${KSK_BASE}.private" \
    /root/namingo-offline-ksk/example/
```

Copy the state file when present:

```bash
if sudo test -f "${KSK_BASE}.state"; then
    sudo cp -a "${KSK_BASE}.state" \
        /root/namingo-offline-ksk/example/
fi
```

Create checksums:

```bash
sudo sh -c \
    'cd /root/namingo-offline-ksk/example && sha256sum Kexample.* > SHA256SUMS'
```

Transfer this directory and `policy.conf` using encrypted removable media to
the offline KSK system. Verify the checksums there.

Do not delete the online KSK private file yet. It is removed only after the SKR
has been imported and the zone has been verified.

#### 3.8.3 Pregenerate online ZSKs

On the hidden primary, create a working directory:

```bash
sudo install -d -m 0700 /root/namingo-offline-ksk/example-work
```

Pregenerate the ZSK schedule. The example creates two years of material:

```bash
sudo dnssec-ksr \
    -K /var/lib/bind \
    -i now \
    -e +2y \
    -k namingo-policy \
    -l /root/namingo-offline-ksk/policy.conf \
    keygen example.
```

Existing keys in `/var/lib/bind` are taken into account. Running the command
again for the same interval should not create another duplicate schedule.

Generate the KSR:

```bash
sudo sh -c '
dnssec-ksr \
    -K /var/lib/bind \
    -i now \
    -e +2y \
    -k namingo-policy \
    -l /root/namingo-offline-ksk/policy.conf \
    request example. \
    > /root/namingo-offline-ksk/example-work/example.ksr
'
```

Create a checksum:

```bash
sudo sh -c '
cd /root/namingo-offline-ksk/example-work
sha256sum example.ksr > example.ksr.sha256
'
```

Copy `example.ksr` and its checksum to encrypted removable media. The KSR may
be transported to the offline system; the ZSK private files must remain on the
hidden primary.

#### 3.8.4 Create the SKR on the offline KSK system

Install BIND 9.20 utilities on the offline system using the appropriate
repository steps from this section:

```bash
sudo apt install -y bind9-utils
dnssec-ksr -V
```

The offline system does not need to run the `named` service.

Use a protected directory:

```bash
sudo install -d -m 0700 /secure/namingo-ksk/example
sudo install -d -m 0700 /secure/namingo-ksk/requests
sudo install -d -m 0700 /secure/namingo-ksk/responses
```

Copy the existing KSK files into `/secure/namingo-ksk/example`, copy the KSR
into `/secure/namingo-ksk/requests`, and copy the matching policy to
`/secure/namingo-ksk/policy.conf`.

Verify the checksums before signing.

Pregenerate any future KSKs required for the requested period:

```bash
sudo dnssec-ksr \
    -K /secure/namingo-ksk/example \
    -i now \
    -e +2y \
    -o \
    -k namingo-policy \
    -l /secure/namingo-ksk/policy.conf \
    keygen example.
```

The `-o` option generates KSKs instead of ZSKs. The existing KSK is considered
when the future KSK schedule is calculated.

Sign the KSR and create the SKR:

```bash
sudo sh -c '
umask 077

dnssec-ksr \
    -K /secure/namingo-ksk/example \
    -i now \
    -e +2y \
    -k namingo-policy \
    -l /secure/namingo-ksk/policy.conf \
    -f /secure/namingo-ksk/requests/example.ksr \
    sign example. \
    > /secure/namingo-ksk/responses/example.skr
'
```

Confirm that the file is non-empty and contains Signed Key Response bundles:

```bash
sudo test -s /secure/namingo-ksk/responses/example.skr
sudo grep -m 1 'SignedKeyResponse' \
    /secure/namingo-ksk/responses/example.skr
```

Create a checksum:

```bash
sudo sh -c '
cd /secure/namingo-ksk/responses
sha256sum example.skr > example.skr.sha256
'
```

Return only the SKR and its checksum to the hidden primary. Keep all KSK private
files on the offline system.

#### 3.8.5 Import the SKR on the hidden primary

Copy the returned files to the hidden primary and verify the checksum:

```bash
cd /tmp
sha256sum -c example.skr.sha256
```

Install the SKR where BIND can read it:

```bash
sudo install -o root -g bind -m 0640 \
    /tmp/example.skr \
    /var/lib/bind/example.skr
```

Now add the following line to the existing `namingo-policy` in the live BIND
configuration:

```conf
offline-ksk yes;
```

Validate and apply the configuration:

```bash
sudo named-checkconf
sudo rndc reconfig
```

Immediately import the SKR:

```bash
sudo rndc skr -import /var/lib/bind/example.skr example.
```

Review the DNSSEC state and the service log:

```bash
sudo rndc dnssec -status example.
sudo rndc signing -list example.
sudo rndc zonestatus example.
sudo journalctl -u named -n 100 --no-pager
```

Reload and notify the secondaries:

```bash
sudo rndc reload example.
sudo rndc notify example.
```

#### 3.8.6 Verify the migrated zone

Query the hidden primary directly:

```bash
dig @127.0.0.1 example. SOA +dnssec
dig @127.0.0.1 example. DNSKEY +dnssec +multiline
dig @127.0.0.1 example. CDS +dnssec +multiline
dig @127.0.0.1 example. CDNSKEY +dnssec +multiline
```

Query every public authoritative server:

```bash
dig @<primary-public-IP> example. SOA +dnssec
dig @<secondary-public-IP> example. SOA +dnssec
dig @<secondary-public-IP> example. DNSKEY +dnssec +multiline
```

Check validation through the normal DNS path:

```bash
delv example. SOA
```

Confirm that:

- the zone answers authoritatively;
- the DNSKEY RRset has a valid RRSIG;
- ordinary zone records continue to be signed by the ZSK;
- the DS visible in the parent still matches the active KSK;
- all secondaries receive the current signed serial; and
- no `offline-ksk`, `SKR`, missing-key, or expired-signature errors appear in
  the BIND log.

Do not remove the current KSK private key from the online server until all
checks pass.

#### 3.8.7 Remove the KSK private material from the online server

After successful verification, copy the final protected KSK archive to its
permanent offline backup and verify it again.

Remove only the KSK private file from the hidden primary:

```bash
sudo rm -f "${KSK_BASE}.private"
```

Leave the public `.key` file and any BIND `.state` file in place unless a
documented rollover procedure explicitly says otherwise.

Remove temporary online exports containing the KSK private key:

```bash
sudo rm -rf /root/namingo-offline-ksk/example
```

Do not keep a second copy in `/root`, `/tmp`, an administrator home directory,
a cloud-synchronised directory, or an ordinary server backup.

Recheck the service after the private key is removed:

```bash
sudo rndc dnssec -status example.
sudo rndc signing -list example.
dig @127.0.0.1 example. DNSKEY +dnssec +multiline
sudo journalctl -u named -n 100 --no-pager
```

### 3.9 Future KSK rollovers

The initial migration can retain the existing parent DS because it reuses the
current KSK. Future KSKs generated on the offline system still require a normal
KSK rollover.

Before a new KSK becomes active:

1. inspect the future DNSKEY, CDS, and CDNSKEY data in the SKR;
2. submit the new DS to the parent according to the parent registry's
   procedure;
3. wait until the new DS is visible from all parent authoritative servers;
4. confirm the DS publication to BIND when required; and
5. remove the old DS only after the policy's rollover conditions are satisfied.

For a manually managed parent, BIND can be informed that the DS is published:

```bash
sudo rndc dnssec -checkds -key <new-key-tag> published example.
```

After the old DS has been removed from the parent and the required propagation
time has passed:

```bash
sudo rndc dnssec -checkds -key <old-key-tag> withdrawn example.
```

Never guess these timings and never remove the old DS merely because a new key
appears in the DNSKEY RRset. Follow the state shown by:

```bash
sudo rndc dnssec -status example.
```

### 3.10 Renew the SKR before it expires

An SKR covers only the interval supplied to `dnssec-ksr`. BIND cannot create
replacement KSK signatures after the imported material ends.

Repeat the workflow before the current SKR approaches its final response
bundle:

1. pregenerate the next online ZSK interval;
2. generate a new KSR;
3. sign it on the offline KSK system;
4. return the new SKR;
5. import it with `rndc skr -import`; and
6. verify the zone and parent DS state.

Use overlapping intervals. Do not wait until the final DNSKEY signature is
close to expiration.

Record at least the following for every signing ceremony:

- zone name;
- KSR start and end times;
- KSR SHA-256 checksum;
- SKR SHA-256 checksum;
- current and future KSK key tags;
- operator names;
- signing date;
- offline media identifier;
- parent DS state; and
- SKR import and verification results.

### 3.11 Adding Offline KSK to additional existing zones

Repeat Sections 3.8 through 3.10 separately for every TLD zone.

Do not copy one zone's KSR, SKR, or KSK files into another zone's directory.
Each zone must have:

- its own KSK material;
- its own KSR;
- its own SKR;
- its own parent DS verification; and
- its own signing and rollover records.

The same `namingo-policy` may be shared by multiple zones only when all of those
zones intentionally use identical algorithms, lifetimes, TTLs, and rollover
parameters.

### 3.12 Failure handling

If `dnssec-ksr` fails, do not enable Offline KSK.

If `rndc skr -import` fails:

1. keep the current KSK private file on the hidden primary;
2. inspect the BIND log;
3. confirm that the policy name and parameters match;
4. confirm that the KSR and SKR use the exact zone name, including its trailing
   dot;
5. confirm that the SKR interval includes the current time;
6. recreate the SKR on the offline system; and
7. retry the import.

If the zone begins returning `SERVFAIL`, restore the previous live
configuration, retain the current online KSK, reload BIND, and investigate
before attempting the migration again:

```bash
sudo named-checkconf
sudo rndc reconfig
sudo rndc reload example.
sudo journalctl -u named -n 200 --no-pager
```

Do not delete keys, state files, journals, or parent DS records as an emergency
shortcut.