# Namingo Registry: gTLD-Specific Setup

This guide outlines the additional configuration steps required for registries operating a generic Top-Level Domain (gTLD) under ICANN policies. It includes guidance on setting up features such as TMCH integration, minimum data set, data escrow, and other compliance-related components.

> ⚠️ This guide assumes you have already completed the basic system and TLD configuration covered in the [Configuration Guide](configuration.md).

## 1. Enable gTLD Mode

Open the file `/opt/registry/automation/config.php` and set both `cron_gtld_mode` and `cron_spec11` to `true`. This enables gTLD-specific features and ICANN Spec 11 abuse checks required for compliance.

## 2. Register an EPP Repository Identifier with IANA

Each gTLD Registry Operator must register an **EPP Repository Identifier** with IANA. This identifier is used as part of the globally unique **Repository Object Identifier (ROID)** assigned to registry objects.

Under RFC 5730, a ROID is normally constructed from a repository-local unique identifier followed by a hyphen (`-`) and the IANA-registered EPP Repository Identifier.

For example, if the registered EPP Repository Identifier is `EXAMPLE`, a domain object's ROID could be:

```text
12345-EXAMPLE
```

The `EXAMPLE` portion is the **EPP Repository Identifier**. It is sometimes informally referred to as the ROID suffix, but the formal IANA term is **EPP Repository Identifier**.

### 2.1. Choose and Register the Identifier

First, check the IANA **EPP Repository Identifiers** registry to ensure that the desired identifier is not already registered:

https://www.iana.org/assignments/epp-repository-ids/epp-repository-ids.xhtml

IANA registrations are made on a First Come First Served basis.

The requested identifier must:

- Be between 1 and 8 characters long.
- Contain only letters or digits as defined by XML.
- Be globally unique in the IANA EPP Repository Identifiers registry.
- Be submitted together with the hexadecimal representation of each character.

ICANN recommends using one repository identifier per TLD where possible. Using the TLD itself is therefore appropriate when it satisfies the IANA format requirements.

For example:

```text
ID: EXAMPLE, #x0045 #x0058 #x0041 #x004D #x0050 #x004C #x0045

Registrant Contact:
Your registry operator contact details

Change Controller:
Your registry operator or other entity authorized to request changes
```

Alternatively, the `ID` field may be specified as `Please assign`, in which case IANA will assign an identifier.

Submit the request to IANA using the protocol parameter assignment process and specify **EPP Repository Identifiers** as the registry being requested:

https://www.iana.org/form/protocol-assignment

Reference RFC 5730 in the request.

Do not begin production use of a repository identifier until the assignment appears in the IANA EPP Repository Identifiers registry.

### 2.2. Configure the Repository Identifier in Namingo

After IANA has registered the identifier, replace the default `XX` value with the assigned EPP Repository Identifier in the Namingo configuration files that contain the `roid` setting.

For example, if IANA assigned `EXAMPLE`:

```php
'roid' => 'EXAMPLE',
```

Set the same value in:

```text
/opt/registry/automation/config.php
/opt/registry/rdap/config.php
/opt/registry/whois/port43/config.php
```

The configured value must exactly match the EPP Repository Identifier registered with IANA.

## 3. RDE (Registry data escrow) configuration

### 3.1. Generate the Key Pair

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

### 3.2. Get the Key Fingerprint

Run:

```bash
gpg2 --with-colons --list-keys your.email@example.com | grep '^fpr' | head -n 1 | cut -d: -f10
```

Or visually:

```bash
gpg2 --list-keys --fingerprint your.email@example.com
```

Copy the 40-character fingerprint (e.g., `C5D2BC6174369B11C7CB1ADB80D7E3572F8BA377`).

### 3.3. Export the Public Key

Use the fingerprint (preferred) or email address to export the public key:

```bash
gpg2 --armor --export C5D2BC6174369B11C7CB1ADB80D7E3572F8BA377 > denic-signing-public.asc
```

```bash
gpg2 --armor --export your.email@example.com > denic-signing-public.asc
```

> Send only `denic-signing-public.asc` to your RDE provider (e.g., DENIC).

### 3.4. Do Not Export or Share the Private Key

Your private key must remain secure and local:

```bash
# Optional: If you need to export the private key for backup (not recommended for transmission)
gpg2 --armor --export-secret-keys C5D2BC6174369B11C7CB1ADB80D7E3572F8BA377 > private-backup.asc
```

> Never send this file to ICANN or any third party.

### 3.5. Configure the Fingerprint in Namingo

Set the value in `/opt/registry/automation/conf.php`:

```bash
'escrow_signing_fingerprint' => 'C5D2BC6174369B11C7CB1ADB80D7E3572F8BA377',
```

## 4. SFTP Server Setup for ICANN

### 4.1. Install OpenSSH Server

```bash
apt update && apt install openssh-server
```

### 4.2. Configure SSH for SFTP

Edit SSH config:

```bash
nano /etc/ssh/sshd_config
```

Add at the end:

```bash
Subsystem sftp internal-sftp

Match Address 192.0.47.240,192.0.32.241,2620:0:2830:241::c613,2620:0:2d0:241::c6a5
    PasswordAuthentication no
    PermitRootLogin no

Match User sftpuser
    ChrootDirectory /home/sftpuser
    ForceCommand internal-sftp
    AllowTcpForwarding no
    X11Forwarding no
```

Restart SSH:

```bash
systemctl restart ssh
```

### 4.3. Create SFTP User

```bash
groupadd sftp_users
useradd -m -G sftp_users -s /usr/sbin/nologin sftpuser
```

### 4.4. Set Directory Permissions

```bash
chown root:root /home/sftpuser
chmod 755 /home/sftpuser
mkdir -p /home/sftpuser/files
chown sftpuser:sftp_users /home/sftpuser/files
chmod 700 /home/sftpuser/files
```

### 4.5. Whitelist ICANN IPs in UFW

```bash
ufw allow OpenSSH
ufw allow from 192.0.47.240 to any port 22
ufw allow from 192.0.32.241 to any port 22
ufw allow from 2620:0:2830:241::c613 to any port 22
ufw allow from 2620:0:2d0:241::c6a5 to any port 22
ufw enable
```

### 4.6. Generate and Add SSH Key for ICANN

```bash
ssh-keygen -t rsa -b 2048 -f icann_sftp_key -C "icann_sftp"
```

```bash
mkdir /home/sftpuser/.ssh
chmod 700 /home/sftpuser/.ssh
nano /home/sftpuser/.ssh/authorized_keys
```

Paste `icann_sftp_key.pub`, then set permissions:

```bash
sudo chmod 600 /home/sftpuser/.ssh/authorized_keys
sudo chown -R sftpuser:sftp_users /home/sftpuser/.ssh
```

### 4.7. Update DNS for `sftp.namingo.org`

Create an A record pointing `sftp.namingo.org` → `<your-server-ip>`.

### 4.8. Send ICANN the Following

- SFTP Host: `sftp://sftp.namingo.org`
- Port: `22`
- Username: `sftpuser`
- Public Key: `icann_sftp_key.pub`
- File Path: `/files`

### 4.9. Test SFTP Access

```bash
sftp -i icann_sftp_key sftpuser@sftp.namingo.org
```

## 5. Minimum Data Set

This document provides guidance on transitioning to the Minimum Data Set for domain registration data. This change requires registries to update their systems to stop accepting specific contact data for domain names. The purpose of this document is to provide an overview of the Minimum Data Set, how to activate it in your configuration files, and the implications of this change.

### 5.1. What is the Minimum Data Set?

The Minimum Data Set is defined as the essential data elements that need to be transferred from the Registrar to the Registry Operator. It includes no personal data, meaning that contact details for registrant, admin, tech, and billing contacts are not included.

This approach is similar to what was previously known as a "thin registry," where only the minimum required information is collected and stored.

### 5.2. Activating the Minimum Data Set

To comply with this new policy, registries need to configure their Namingo instances to activate the Minimum Data Set mode. This involves setting the **minimum_data** variable to true in each component's configuration file.

#### 5.2.1. Locate the Configuration File:

Each component in your system (e.g., EPP server, Whois server) has a configuration file. These files are typically named config.php, .env, or similar, depending on your setup.

#### 5.2.2. Set Minimum Data to True:

Open the configuration file and find the setting for **minimum_data**. Set this variable to **true** to activate the Minimum Data Set mode.

Example for a PHP configuration file (config.php):

```php
return [
    'minimum_data' => true,
    // other settings...
];
```

#### 5.2.3. Restart Your Services:

After updating the configuration files, restart your services to apply the changes. This ensures that the new settings take effect.

### 5.3. Impact of Activating Minimum Data Set

Once the Minimum Data Set mode is activated:

- Contact details (registrant, admin, tech, and billing) will no longer be collected or sent to the Registry Operator.

- Your registry system will operate in a manner consistent with a "thin registry."

### 5.4. Purging Existing Contact Details

Registries can manually purge current contact details if and when needed. It is recommended to purge this data to fully comply with the new policy. For now, once you turn on the Minimum Data Set mode, it is advised not to turn it off again to ensure consistency and compliance.

### 5.5. Conclusion

Transitioning to the Minimum Data Set is a significant step in enhancing privacy and compliance with updated registration data policies. By following the steps outlined in this document, registrars can ensure a smooth transition and continued compatibility with the new requirements.

## 6. ICANN Reporting

Namingo automatically generates ICANN-required reports and uploads them via SFTP. These reports are generated in the directory specified by the `reporting_path` value in `/opt/registry/automation/config.php`.

To enable automatic uploading, set the following values:

- `reporting_upload` → `true`
- `reporting_username` → your ICANN-issued username
- `reporting_password` → your ICANN-issued password

The primary report generated is the **Registry Operator Monthly Report**, which includes statistics on domain transactions, contact counts, DNS activity, abuse cases, and more — required monthly by ICANN for all gTLD operators.

## 7. LORDN File Generation

Namingo supports automatic generation and submission of the **LORDN (Launch OR Derivatives Notification)** file to ICANN.

To enable this:

- Set `lordn_user` and `lordn_pass` in `/opt/registry/automation/config.php` under the automation settings.

Once enabled, Namingo will generate the LORDN XML file and securely transmit it to the ICANN endpoint on your behalf.

## 8. TMCH Integration

TMCH (Trademark Clearinghouse) integration allows the registry to receive sunrise claims information.

To enable TMCH support:

- Edit the `tmch` section in `/opt/registry/automation/config.php`
- Add the required credentials and API endpoints provided by the TMDB (TMCH Database provider)

Namingo will automatically pull TMCH records and store them in the registry database for use during sunrise validations and claims notices.

## 9. URS Integration

URS (Uniform Rapid Suspension) support allows the registry to process complaints issued through ICANN’s URS system.

To enable URS handling:

- Configure the `urs` section in `/opt/registry/automation/config.php` with the credentials for the designated URS mailbox.

Namingo will regularly scan this mailbox for incoming URS case notifications. Detected cases will be parsed, recorded, and automatically added to the registry system as internal tickets assigned to the affected registrar.

## 10. Spec 11 Monitoring

Spec 11 abuse monitoring is required under the ICANN Registry Agreement for gTLDs. Namingo fulfills this by scanning all active domains against several public threat intelligence sources, including databases listing:

- Malware
- Phishing
- Botnets
- Spam abuse

If a domain is flagged:

- A ticket is created in the affected registrar’s account
- An abuse report is sent automatically to the registrar via email

To use this feature, ensure `cron_spec11` is set to `true` in `/opt/registry/automation/config.php`.

> This functionality helps maintain registry compliance with Specification 11 and supports proactive registrar communication.

## 11. ICANN MoSAPI Integration

This tool connects to ICANN MoSAPI to monitor TLD-specific compliance and abuse statistics for registry operators.

### What It Does

- Authenticates with MoSAPI using registry credentials
- Retrieves per-TLD state, including service status and incident data (`/monitoring/state`)
- Retrieves latest abuse metrics for delegated domains (`/metrica/domainList/latest`)

### Output Includes

- TLD status and tested interfaces (e.g. RDAP, EPP)
- Emergency threshold settings and active incident logs
- Abuse types (e.g. spam, botnetCc) with domain counts

### Requirements

- PHP 8.2+
- MoSAPI registry credentials

### Usage

Configure your details in `/opt/registrar/tests/icann_mosapi.php` and then run:

```bash
php icann_mosapi.php
```

## 12. ICANN RST

### 12.1. EPP Server Configuration

#### 12.1.1. Modify `/opt/registry/epp/extensions.json`

Ensure the following EPP extensions are **disabled** (i.e., `"enabled": false`) or **enabled** where noted:

```json
{
  "urn:ietf:params:xml:ns:epp:loginSec-1.0": {
    "enabled": false
  },
  "urn:ietf:params:xml:ns:epp:unhandled-namespaces-1.0": {
    "enabled": false
  },
  "urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0": {
    "enabled": false
  },
  ...
  "urn:ietf:params:xml:ns:mark-1.0": {
    "enabled": false
  },
  "https://namingo.org/epp/funds-1.0": {
    "enabled": false
  },
  "https://namingo.org/epp/identica-1.0": {
    "enabled": false
  }
}
```

#### 12.1.2. Modify `/opt/registry/epp/config.php`

Ensure the following configuration options are present and set as indicated below. If the keys do not exist, add them:

```php
<?php
...
    'ns_mode' => 'hostObj', // 'hostObj' | 'hostAttr'

    // Enforce TLS client certificate validation
    'mandatory_client_ssl' => true,

    // Disable the 60-day inter-registrar transfer lock
    'disable_60days' => true,

];
```