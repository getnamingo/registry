# Initial Operation Guide (IOG)

Welcome to the Initial Operation Guide for Namingo. This document is designed to assist you in the initial setup and operation of your Namingo system. Follow these steps to ensure a smooth start and efficient management of your domain registry.

## Deleting Test Data

1. **Deleting Registrars and Test TLDs**: To delete test registrars and TLDs, edit the `/opt/registry/tests/clean-test-details.php` script with your database details and then execute it. It is advisable to delete test data before adding new data to the system.

## Logging into the Control Panel

1. **Access the Panel**: Begin by logging in to the control panel as the registry administrator. This is the user account you created during the installation process.
2. **Familiarize Yourself**: Once logged in, take some time to explore the interface and familiarize yourself with the various features and settings available.

## Configuring Your Registry

1. **Navigate to Registry Configuration**: In the control panel, go to the `registry-configuration` menu.
2. **Set Up Registry Details**: Here, you will configure essential details such as:
    - **Registry Operator Name**: The official name of your registry.
    - **Registry Handle**: A unique identifier for your registry.
    - **WHOIS/RDAP Server URLs**: The URLs for your WHOIS and RDAP servers.
    - **Registry VAT/Company Number**: Your registry's tax or company identification number.
    - **Registry Contact Information**: Address, email, and phone number for registry contact.
    - **Launch Phase Extension**: Decide whether to enable the launch phase extension feature.

## Adding Top-Level Domains (TLDs)

1. **Go to Registry TLDs Menu**: In the control panel, select the `registry-TLDs` menu.
2. **Add Your TLD**: You can add a new TLD by specifying details such as:
    - **Extension**: The TLD extension (e.g., .com, .org).
    - **Type**: Whether it's a ccTLD or gTLD.
    - **Supported Script**: The script types supported by the TLD.
    - **Pricing**: Set prices for registration and renewal.
    - **Premium Names**: Define any premium domain names.
3. **Manage Reserved Names**: In this section, you can also manage names that are reserved and not available for general registration.
4. **Update Configuration Files**: Edit `/opt/registry/epp/config.php` and `/var/www/cp/.env` to set the `test_tlds` variables to your TLD(s).

## Managing Registrars

1. **Create a Registrar**: Navigate to the **Registrars** section, select **Create Registrar** from the menu, and fill in the required details to add your first registrar to the system.

## Testing Your Registry Setup

1. **Create a Contact**: Begin by creating a contact in the system. This contact will be used in the domain registration process.
2. **Register a Domain**: Use the contact you created to register a domain under one of your TLDs. This step will help you experience the end-to-end process of domain registration just as your registrars would.
3. **Verify with WHOIS and RDAP**: After registering the domain, utilize the WHOIS and RDAP services to verify that the domain has been successfully added to the system.
4. **Validate the Setup**: Completing this process not only familiarizes you with the platform’s functionality but also serves as a validation to ensure all components of your registry are functioning as expected. This step is crucial in preparing for managing live domain registrations.

---

Upon completing the steps outlined above, you are now fully equipped to manage your domain registry using Namingo. With the registry and TLD configurations set, and your first registrar added, your system is operational and ready for domain management. This marks a significant milestone in your journey with Namingo, paving the way for efficient and streamlined registry operations. Remember, the Namingo platform is designed to be intuitive and user-friendly, ensuring a smooth management experience as you grow and evolve your domain registry services.