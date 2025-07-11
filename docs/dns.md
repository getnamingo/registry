# Namingo Registry: DNS Setup Guide

This guide walks you through configuring the core DNS setup for your Namingo-powered registry. It includes creating a hidden master DNS server, which acts as the authoritative source for all zone data. Your TLD DNS servers (public-facing) will be configured to receive updates from this hidden master.

> ⚠️ This setup is [Required] for proper zone publication and delegation of domains under your TLDs.

## 1. Hidden Master DNS Server Setup

This section covers how to set up your hidden master — the central source Namingo updates with zone changes. Public-facing DNS servers (e.g., slaves or Anycast nodes) will pull zones from it and serve them to the world.

> ⚠️ Choose only **one** of the following DNS backends based on your environment and preferences. Do **not** install all of them.

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

> ⚠️ **Choose one configuration from the following section that fits your setup. Do not combine them.**

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

> 🔒 **Security Tip:** DNSSEC private keys must be properly secured.

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

> ⚠️ **Important:** In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

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

> ⚠️ **Important:** In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

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

> ⚠️ **Important:** In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

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

> ✅ You should see something like: `zone test/IN: loaded serial 2025041901` indicating success.

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

> ⚠️ **Important:** In the **Control Panel → TLD Management**, click the **Enable DNSSEC** button. After the keys are created, the **DS Record** will appear on the same page and should be submitted to **IANA** or the parent registry.

### 1.4. NSD (Advanced)

NSD is a high-performance, authoritative-only name server with no support for dynamic updates, inline signing, or native DNSSEC key management. It is suitable for production environments that prioritize speed and simplicity, but it **requires external zone signing**.

> ⚠️ **Important:** NSD does **not** support DNSSEC signing or key management internally. You must handle all DNSSEC operations — such as key generation, zone signing, rollover, and DS management — using external tools like `ldns-signzone`, `dnssec-signzone`, or OpenDNSSEC.

#### 1.4.1. Prerequisites

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

#### 1.4.2. Installation

```bash
apt install nsd ldnsutils
```

#### 1.4.3. Configure NSD to Load Signed Zone

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

#### 1.4.4. Automating Zone Signing

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

> 🧠 **Tip:** For easier DNSSEC lifecycle management, consider using **OpenDNSSEC** as the signer and **NSD** only for serving the signed zones.

### 1.5. Other Options

You may integrate with PowerDNS, YADIFA, or other servers, but this requires custom adaptation.

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

### 2.3. Adjusting Permissions and Ownership

Ensure BIND has permission to write to the zone file and that the files are owned by the BIND user:

```bash
chown bind:bind /var/cache/bind/zones
chmod 755 /var/cache/bind/zones
```

### 2.4. Enabling Logs

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

### 2.5. Validate and Apply Configuration

After completing your secondary zone setup, check for syntax errors using `named-checkconf`, then restart BIND9 using `systemctl restart bind9` to apply the changes.

To verify that the zone was successfully transferred from the hidden master, check your logs with `grep 'transfer of "test."' /var/log/syslog`. You should see a log entry confirming the successful zone transfer.