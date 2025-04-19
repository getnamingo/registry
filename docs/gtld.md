# Namingo Registry: gTLD-Specific Setup

This guide outlines the additional configuration steps required for registries operating a generic Top-Level Domain (gTLD) under ICANN policies. It includes guidance on setting up features such as TMCH integration, minimum data set, data escrow, and other compliance-related components.

> ⚠️ This guide assumes you have already completed the basic system and TLD configuration covered in the [Configuration Guide](configuration.md).

## 1. Enable gTLD Mode

Open the file `/opt/registry/automation/cron_config.php` and set both `gtld_mode` and `spec11` to `true`.

Change these lines:

- `'gtld_mode' => true`
- `'spec11' => true`

> ✅ This enables gTLD-specific features and ICANN Spec 11 abuse checks required for compliance.

## 2. RDE (Registry data escrow) configuration

### 2.1. Generate the Key Pair

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

### 2.2. Exporting Your Keys

Public key:

```bash
gpg2 --armor --export your.email@example.com > publickey.asc
```

Replace `your-email@example.com` with the email address you used when generating the key.

Private key:

```bash
gpg2 --armor --export-secret-keys your.email@example.com > privatekey.asc
```

### 2.3. Secure Your Private Key

Always keep your private key secure. Do not share it. If someone gains access to your private key, they can impersonate you in cryptographic operations.

### 2.4. Use in RDE deposit generation

Please send the exported `publickey.asc` to your RDE provider, and also place the path to `privatekey.asc` in the escrow.php system as required.

## 3. SFTP Server Setup for ICANN

### 3.1. Install OpenSSH Server

```bash
apt update && apt install openssh-server
```

### 3.2. Configure SSH for SFTP

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

### 3.3. Create SFTP User

```bash
groupadd sftp_users
useradd -m -G sftp_users -s /usr/sbin/nologin sftpuser
```

### 3.4. Set Directory Permissions

```bash
chown root:root /home/sftpuser
chmod 755 /home/sftpuser
mkdir -p /home/sftpuser/files
chown sftpuser:sftp_users /home/sftpuser/files
chmod 700 /home/sftpuser/files
```

### 3.5. Whitelist ICANN IPs in UFW

```bash
ufw allow OpenSSH
ufw allow from 192.0.47.240 to any port 22
ufw allow from 192.0.32.241 to any port 22
ufw allow from 2620:0:2830:241::c613 to any port 22
ufw allow from 2620:0:2d0:241::c6a5 to any port 22
ufw enable
```

### 3.6. Generate and Add SSH Key for ICANN

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

### 3.7. Update DNS for `sftp.namingo.org`

Create an A record pointing `sftp.namingo.org` → `<your-server-ip>`.

### 3.8. Send ICANN the Following

- SFTP Host: `sftp://sftp.namingo.org`
- Port: `22`
- Username: `sftpuser`
- Public Key: `icann_sftp_key.pub`
- File Path: `/files`

### 3.9. Test SFTP Access

```bash
sftp -i icann_sftp_key sftpuser@sftp.namingo.org
```

## 4. Minimum Data Set

This document provides guidance on transitioning to the Minimum Data Set for domain registration data. This change requires registries to update their systems to stop accepting specific contact data for domain names. The purpose of this document is to provide an overview of the Minimum Data Set, how to activate it in your configuration files, and the implications of this change.

### 4.1. What is the Minimum Data Set?

The Minimum Data Set is defined as the essential data elements that need to be transferred from the Registrar to the Registry Operator. It includes no personal data, meaning that contact details for registrant, admin, tech, and billing contacts are not included.

This approach is similar to what was previously known as a "thin registry," where only the minimum required information is collected and stored.

### 4.2. Activating the Minimum Data Set

To comply with this new policy, registries need to configure their Namingo instances to activate the Minimum Data Set mode. This involves setting the **minimum_data** variable to true in each component's configuration file.

#### 4.2.1. Locate the Configuration File:

Each component in your system (e.g., EPP server, Whois server) has a configuration file. These files are typically named config.php, .env, or similar, depending on your setup.

#### 4.2.2. Set Minimum Data to True:

Open the configuration file and find the setting for **minimum_data**. Set this variable to **true** to activate the Minimum Data Set mode.

Example for a PHP configuration file (config.php):

```php
return [
    'minimum_data' => true,
    // other settings...
];
```

#### 4.2.3. Restart Your Services:

After updating the configuration files, restart your services to apply the changes. This ensures that the new settings take effect.

### 4.3. Impact of Activating Minimum Data Set

Once the Minimum Data Set mode is activated:

- Contact details (registrant, admin, tech, and billing) will no longer be collected or sent to the Registry Operator.

- Your registry system will operate in a manner consistent with a "thin registry."

### 4.4. Purging Existing Contact Details

Registries can manually purge current contact details if and when needed. It is recommended to purge this data to fully comply with the new policy. For now, once you turn on the Minimum Data Set mode, it is advised not to turn it off again to ensure consistency and compliance.

### 4.5. Conclusion

Transitioning to the Minimum Data Set is a significant step in enhancing privacy and compliance with updated registration data policies. By following the steps outlined in this document, registrars can ensure a smooth transition and continued compatibility with the new requirements.