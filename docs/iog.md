# Namingo Registry: First Steps

Welcome to the **First Steps Guide** for Namingo. This document will walk you through the essential steps following your Namingo installation, helping you prepare your system for production use. These steps include deleting test data, logging into the control panel, configuring your registry and TLDs, adding registrars, and validating your setup.

> **Before You Begin**: Ensure you’ve completed the [Configuration Guide](configuration.md) and can access the admin control panel.

---

## Step 1: Clean Up Test Data

1. **Remove Default Registrars & TLDs**  
   Navigate to `/opt/registry/tests/clean-test-details.php` and edit it to include your database credentials.

2. **Execute the Script**  
   Run the script to remove all default test registrars and TLDs:
```bash
php /opt/registry/tests/clean-test-details.php
```

> It's recommended to delete test data before adding real data to avoid conflicts or clutter.

---

## Step 2: Log into the Control Panel

1. Open the control panel in your browser.
2. Log in as the registry administrator (created during installation).
3. Explore the menus: take a moment to familiarize yourself with the interface and structure.

---

## Step 3: Configure Your Registry

1. Go to `Registry → Configuration`.
2. Fill in the following key settings:

   - **Registry Operator Name**: e.g., `Namingo Ltd.`
   - **Registry Handle**: Unique ID like `namingo`
   - **WHOIS Server URL**: e.g., `whois.example.tld`
   - **RDAP Base URL**: e.g., `https://rdap.example.tld/`
   - **VAT or Company Number**
   - **Contact Details**: Address, phone, email
   - **Launch Phase Extension**: Enable if you plan to use Sunrise/Landrush phases

> You can return to this section anytime to update settings.

---

## Step 4: Add Top-Level Domains (TLDs)

1. Navigate to `Registry → TLDs → Create New TLD`.

2. Provide the following details:
   - **TLD Extension**: (e.g., `.demo`, `.test`)
   - **Supported Script**: e.g., `Latin`, `Cyrillic`
   - **Pricing**: Registration, Renewal, Restore
   - **Premium Names** *(optional)*: Upload or define

3. Configure per-TLD options:
   - `Manage Settings`: Basic configuration
   - `Manage Promotions`: Discounts or special offers
   - `Manage Launch Phases`: Sunrise, Landrush, General Availability
   - `Export IDN Table`: For IANA submission
   
4. Configure global options:
   - `Reserved Names`: Manage domains that cannot be registered
   - `Allocation Tokens` *(optional)*: Control access during launch phases

4. Update config files:
   - `Update Configuration Files`: Edit `/opt/registry/epp/config.php` and `/var/www/cp/.env` to set the `test_tlds` variables to your TLD(s).

---

## Step 5: Add Registrars

1. Go to `Registrars → Create Registrar`.
2. Fill in the following details:
   - **Registrar Name**
   - **IANA ID** or internal registry identifier
   - **Admin and Technical Contact Info**
   - **WHOIS Server / RDAP Base URL** (if applicable)
   - **Notification Emails**

3. After creation, you can:
   - **Manage Registrar**: Edit status, credentials, and settings
   - **Registrar Details**: View all saved information
   - **Manage Custom Pricing**: Override default TLD pricing  
     *(Note: Manual config required before version 1.0.19 — see [custom-registrar-pricing.md](docs/custom-registrar-pricing.md))*
   - **Impersonate**: Log in as the registrar for testing purposes

> Use impersonation only for internal testing or support – all actions are logged.

---

## Step 6: Test the System

Validate that everything works as expected before going live:

1. **Create a Contact**  
   Navigate to `Contacts → Create Contact` and enter sample registrant information.

2. **Register a Domain**  
   Go to `Domains → Create Domain`, select a TLD, and use your contact.

3. **Check WHOIS and RDAP**  
   Use terminal or online tools to confirm the domain is live:
```bash
whois example.demo
curl https://rdap.example.tld/domain/example.demo
```

> **This is your end-to-end test**:  
> If registration works and the domain data is visible via WHOIS and RDAP, your registry setup is fully functional and ready for production use.

---

## You're Ready to Go

With your registry now configured, at least one TLD active, and registrars added, your Namingo system is operational and production-ready.

You’ve completed the essential First Steps. From here, you can:

- Start onboarding registrars
- Launch TLDs with phased rollout (Sunrise, Landrush, General)
- Enable premium/reserved domain policies

Welcome to Namingo — the open, flexible registry platform for your domain ambitions!