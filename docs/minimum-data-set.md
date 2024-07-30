# Minimum Data Set Documentation	

This document provides guidance on transitioning to the Minimum Data Set for domain registration data. This change requires registries to update their systems to stop accepting specific contact data for domain names. The purpose of this document is to provide an overview of the Minimum Data Set, how to activate it in your configuration files, and the implications of this change.

## What is the Minimum Data Set?

The Minimum Data Set is defined as the essential data elements that need to be transferred from the Registrar to the Registry Operator. It includes no personal data, meaning that contact details for registrant, admin, tech, and billing contacts are not included.

This approach is similar to what was previously known as a "thin registry," where only the minimum required information is collected and stored.

## Activating the Minimum Data Set

To comply with this new policy, registries need to configure their Namingo instances to activate the Minimum Data Set mode. This involves setting the **minimum_data** variable to true in each component's configuration file.

### Steps to Activate:

#### 1. Locate the Configuration File:

Each component in your system (e.g., EPP server, Whois server) has a configuration file. These files are typically named config.php, .env, or similar, depending on your setup.

#### 2. Set Minimum Data to True:

Open the configuration file and find the setting for **minimum_data**. Set this variable to **true** to activate the Minimum Data Set mode.

Example for a PHP configuration file (config.php):

```php
return [
    'minimum_data' => true,
    // other settings...
];
```

### 3. Restart Your Services:

After updating the configuration files, restart your services to apply the changes. This ensures that the new settings take effect.

## Impact of Activating Minimum Data Set

Once the Minimum Data Set mode is activated:

- Contact details (registrant, admin, tech, and billing) will no longer be collected or sent to the Registry Operator.

- Your registry system will operate in a manner consistent with a "thin registry."

## Purging Existing Contact Details

Registries can manually purge current contact details if and when needed. It is recommended to purge this data to fully comply with the new policy. For now, once you turn on the Minimum Data Set mode, it is advised not to turn it off again to ensure consistency and compliance.

## Conclusion

Transitioning to the Minimum Data Set is a significant step in enhancing privacy and compliance with updated registration data policies. By following the steps outlined in this document, registrars can ensure a smooth transition and continued compatibility with the new requirements.