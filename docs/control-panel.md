# 1. Introduction

Welcome to the **Namingo Control Panel** – a powerful and intuitive interface for registry administrators and registrars to manage domains, registrars, hosts, contacts, and registry operations. Designed to streamline EPP and registry interactions, this dashboard offers a clear, responsive UI that simplifies day-to-day management tasks.

This manual will guide you through the major components and features of the Namingo Control Panel, providing step-by-step insights based on the interface layout and functionality.

---

# 2. Dashboard

The **Dashboard** is the main overview screen that provides quick insights into your system's activity and current data. It includes visual graphs, counters, and recent activity logs.

## Overview Cards

- **Domains** – Total number of registered domains.
- **Contacts** – Count of contact records available in the system.
- **Hosts** – Total number of nameservers or host objects.
- **Registrars** – Number of registrar accounts configured.

## Visual Charts

- **Last 7 Days: Domain Registrations**  
  Bar chart showing the number of domains registered per day over the last week.

- **Top Registrars by Domains**  
  A pie chart displaying the distribution of domains among different registrars.

- **Support Tickets: Last Week**  
  Displays recent support activity.

## Recent Activity Tables

### Recent Domains
- A table displaying the 10 most recently registered domains.
- **Columns:**
  - **Name**: The registered domain name.
  - **Creation Date**: Timestamp of the domain's registration.
- Each domain entry is clickable, directing users to the domain's detailed management page.

### Recent Support Tickets
- A table showing the 5 most recent support tickets.
- **Columns:**
  - **Subject**: The subject of the support ticket.
  - **Status**: The current status of the ticket (e.g., Open, Closed).
  - **Priority**: The priority level assigned to the ticket (e.g., Low, Medium, High).
- Each ticket entry is clickable, allowing users to view and manage the ticket details.

## Dashboard Actions

### View Reports
- A button located in the top-right corner.
- Directs users to the Reports section, where detailed analytics and reports can be generated.

### Create New Domain
- A button located beside the **View Reports** button.
- Opens a form to manually register a new domain within the registry.

### Language and Account Settings

- **Language Selector**: Located in the top-right corner, allowing users to switch between available languages.
- **User Menu**: Click on the user avatar (e.g., `AD`) to access account settings, logout, and other user-specific options.

---

# 3. Domains List Page

The **Domains** section of the Namingo Control Panel provides a centralized interface for viewing and managing all domain records in the registry system. This list view is optimized for both large-scale TLD operations and fine-grained domain-level tasks, offering a responsive, filterable, and action-driven experience.

## Features and Interface Elements

### Top Controls

- **Export Buttons**
  - **CSV**: Export the visible list of domains in Comma-Separated Values format.
  - **PDF**: Generate a print-ready PDF report of the domain list.

- **Check Domain** (🔍)
  - Opens a domain availability search field.
  - Lets administrators or registrars check whether a domain name is available for registration or already exists.

- **Create Domain** (➕)
  - Launches the domain creation form.
  - Used for manually registering a new domain via the UI.

- **Search Bar**
  - Real-time filtering of the domain list by keyword (e.g., domain name, registrant ID).
  - Supports partial matches and case-insensitive search.

## Domain Table Columns

| Column | Description |
|--------|-------------|
| **Name** | Displays the domain's full name (FQDN). Usually a clickable link to the domain details page. |
| **Registrant** | Shows the unique identifier or handle of the registrant contact associated with the domain. |
| **Creation Date** | Timestamp of when the domain was first registered. Uses millisecond precision for registry auditing. |
| **Expiration Date** | Timestamp indicating when the domain will expire unless renewed. |
| **Status** | A tag-style visual showing the domain’s current EPP status (e.g., `ok`, `pendingDelete`, `clientUpdateProhibited`, etc.). Multiple statuses may be displayed depending on the domain state. |

## Domain Statuses

Common domain statuses include (but are not limited to):

- `ok`: Domain is active and in good standing.
- `addPeriod`: Domain is within the add grace period after initial registration.
- `pendingDelete`: Domain is scheduled for deletion and cannot be renewed.
- `clientUpdateProhibited`: Client-side restriction preventing domain updates.
- `serverHold`: Domain is inactive at the registry level.

These statuses are color-coded (green, orange, red) for faster visual scanning.

## Action Icons (Per Row)

Each domain row includes a set of action buttons depending on the domain’s lifecycle state:

- 🖊️ **Edit**
  - Opens the domain details/edit page.
  - Allows updating DNS settings, contact associations, and status flags.

- 👁️ **View**
  - Opens a read-only page showing domain details, including registry events and current status.

- 🔁 **Renew**
  - Available when the domain is active.
  - Initiates a renewal for the selected domain, extending its expiration date.

- 🗑️ **Delete**
  - Moves the domain into `pendingDelete` or `redemptionPeriod` depending on registry policy.
  - Only visible if the domain is eligible for deletion.

- ♻️ **Restore**
  - Available when the domain is in redemption or pending delete phase.
  - Restores the domain to an active state and may incur a restore fee per registry policy.

> Additional buttons may appear depending on enabled modules or extensions.

## UX Notes

- All actions are permission-guarded: only authorized roles (e.g., `admin`, `registry operator`) can see or execute sensitive actions like deletion, restoration, or editing restricted fields.
- Users only see domains associated with their registrar account or privileges. For example:
  - **Registrar users** see only domains under their own registrar's management.
  - **Registry operators** and **admins** see all domains across the system.
- The table supports column sorting by **Name**, **Creation Date**, and **Expiration Date** for quick access and filtering.
- If multi-language UI is enabled, all status labels, action buttons, and system messages are localized according to the user’s selected language or browser preference.

## 3.1 Applications List

The **Applications** section in the Namingo Control Panel displays all domain applications submitted during special registration phases, such as Sunrise or Landrush. Applications are similar to domains in structure but represent a *pending request* to register a domain name under specific eligibility or policy constraints.

### Purpose

This interface allows registry administrators to:
- View incoming domain applications.
- Evaluate application data (such as claims, signed marks, allocation tokens).
- Approve or reject based on registry policies or validation rules.

### Application Table Columns

| Column | Description |
|--------|-------------|
| **Name** | The domain name being applied for. Often submitted during a launch phase (e.g., `Sunrise`). |
| **Applicant** | Registrant contact ID or handle associated with the application. |
| **Creation Date** | The timestamp when the application was received. |
| **Phase** | The launch phase (e.g., `sunrise`, `landrush`, `custom`) during which the application was submitted. |
| **Status** | Current status of the application, such as `pending`, `validated`, `rejected`, or `approved`. |

### Application Statuses

- `pending`: The application is received and awaiting review.
- `validated`: The application has passed basic checks (e.g., valid SMD, allocation token).
- `approved`: The domain can now be created from the application.
- `rejected`: The application has been denied, often with a reason.

Statuses are displayed with visual tags (color-coded) for rapid scanning.

### Actions (Per Row)

| Icon | Action |
|------|--------|
| 🖊️ | **Edit** – View and edit application metadata if allowed (e.g., update documents or allocation tokens). |
| ✅ | **Approve** – Converts the application into an active domain, completing the lifecycle. |
| ❌ | **Reject** – Marks the application as rejected, optionally prompting for a rejection reason. |
| 📄 | **View** – Opens a read-only view of the full application details, including supporting documents. |

### UX Notes

- Only authorized users (e.g., `registry admin`) can approve or reject applications.
- Applications can be filtered and sorted by domain name, phase, submission date, or status.
- Registrars may view only their own submitted applications.
- Export buttons may be provided (CSV, PDF) for audit trail and compliance.

## 3.2 Transfers List

The **Transfers** section shows all ongoing and completed domain transfer requests between registrars. This section provides oversight and control over the transfer process, especially in registries with manual review enabled.

### Purpose

This interface allows registry staff to:
- View pending inbound and outbound transfers.
- Monitor EPP-based transfer requests.
- Approve, reject, or cancel transfer operations when manual approval is required.
- Manually initiate new transfer requests.

### Transfer Table Columns

| Column | Description |
|--------|-------------|
| **Domain Name** | The domain for which the transfer is being requested. |
| **From Registrar** | The registrar currently managing the domain. |
| **To Registrar** | The gaining registrar requesting the transfer. |
| **Request Date** | Timestamp when the transfer was initiated via EPP. |
| **Status** | Current state of the transfer (e.g., `pending`, `approved`, `rejected`, `cancelled`). |

### Transfer Statuses

- `pending`: Transfer request has been received and is awaiting approval.
- `approved`: Transfer has been accepted and processed.
- `rejected`: Transfer was denied (often by registry intervention).
- `cancelled`: Transfer was canceled before completion (by requesting registrar or timeout).

### Actions (Per Row)

| Icon | Action |
|------|--------|
| ✅ | **Approve** – Accept the transfer request. |
| ❌ | **Reject** – Deny the request based on policy or validation failure. |
| 🔄 | **Cancel** – Cancel the pending request (if still within grace period). |
| 👁️ | **View** – Inspect full transfer metadata and logs. |

### UX Notes

- Transfer visibility is limited based on user role:
  - Registrars see only transfers involving their own account (as gaining or losing registrar).
  - Registry users can view and act on all transfer requests.
- Actions depend on whether manual transfer approval is enabled in registry configuration.
- Transfer logs and timestamps are retained for audit purposes and compliance.

---

# 4. Contacts

The **Contacts** section in the Namingo Control Panel displays all EPP contacts in the registry. Each contact serves as a reusable entity that can be linked to domains in roles such as registrant, admin, tech, or billing. 

## Purpose

This section allows registry staff and registrar users to:
- View and manage existing contact records.
- Create, update, or delete contact handles.
- Audit contact usage and ensure data compliance.

## Contact Table Columns

| Column | Description |
|--------|-------------|
| **Identifier** | Unique contact ID (handle) used in EPP operations. Clickable to view full contact details. |
| **Email** | The contact's registered email address. |
| **Phone** | Contact’s international telephone number. |
| **Creation Date** | The timestamp when the contact was created in the registry system. |

## Actions (Per Row)

| Icon | Action |
|------|--------|
| 🖊️ | **Edit** – Opens the contact edit form to update postal info, phone, or email. |
| 👁️ | **View** – Shows a read-only view of full contact data including address and disclosure preferences. |
| 🗑️ | **Delete** – Removes the contact, if not currently associated with any active domain object. A warning or block appears if the contact is in use. |

## UX Notes

- Contacts can only be deleted if not linked to active domains.
- The system automatically validates phone/email format and enforces uniqueness for handles.
- Registrars can only see and manage their own contact objects.
- Export options (CSV, PDF) are available for audit logs or compliance purposes.
- Creation of contacts is done via the **Create Contact** button.

---

# 5. Hosts

The **Hosts** section lists all registered host objects (nameservers) in the registry. Hosts can be either internal (managed directly by the registry) or external (linked to IPs and glue records).

## Purpose

This section enables:
- Registry and registrar users to manage nameservers.
- Creation and association of host objects to domain records.
- Updating of IP addresses (glue records) for hostnames.

## Host Table Columns

| Column | Description |
|--------|-------------|
| **Host Name** | Fully qualified domain name of the host (e.g., `ns1.example.tld`). Clickable to view details. |
| **Creation Date** | Timestamp of when the host was created. |
| **Last Updated** | Timestamp of the last change made to this host (e.g., IP change, rename). |

## Actions (Per Row)

| Icon | Action |
|------|--------|
| 🖊️ | **Edit** – Modify the host’s name or IP addresses (both IPv4 and IPv6). |
| 👁️ | **View** – Read-only details including glue IPs, linked domains, and timestamps. |
| 🗑️ | **Delete** – Removes the host if not referenced by any domain as a nameserver. Deletion is blocked if the host is in use. |

## UX Notes

- Hostnames must be valid FQDNs under an authorized TLD.
- Glue records (IP addresses) are required for internal hosts and must be valid IPv4/IPv6.
- Host deletion checks for dependencies before allowing removal.
- Registrars can only manage hosts under domains they control, depending on registry policy.
- Search and sort functionality is available for fast access.
- Hosts can be exported as CSV or PDF for operational or compliance purposes.

---

# 6. Registrars

The **Registrars** section provides registry administrators with full visibility and control over accredited registrar accounts. It includes balance management, impersonation, custom pricing, and direct messaging functionality.

This section is restricted to registry operators and administrators. Registrars can view their own registrar details only.

## Registrar Table Columns

| Column | Description |
|--------|-------------|
| **Name** | Display name of the registrar (linked to full profile). |
| **IANA ID** | The IANA-assigned identifier (or internal ID if custom). |
| **Email** | Primary contact email for registrar operations. |
| **Balance** | Current account balance in the configured registry currency. |

## Actions (Per Registrar)

| Icon | Action |
|------|--------|
| 🖊️ | **Edit** – Modify registrar details including IANA ID, contact info, RDAP base URL, notification settings, and billing preferences. |
| 👁️ | **View** – Read-only overview of the registrar’s profile, user accounts, and operational history. |
| 💰 | **Manage Custom Pricing** – Define per-TLD pricing overrides for create, renew, transfer, restore, and delete actions. |
| 👤 | **Impersonate** – Log in as the registrar to test or debug their control panel experience without sharing credentials. Requires confirmation before redirection. |

## Registrar Menu (Top Right)

Additional administrative actions:

- **Create Registrar** – Opens a form to onboard a new registrar with all mandatory fields.
- **Search** – Filter registrars by name, IANA ID, or email.

## Additional Features

- **Notify Registrar(s)** – Compose and send a system-wide message to one or more registrars. Useful for maintenance announcements, policy updates, or reminders.
- **Registrar Users** – View all system users associated with registrars, including roles and activity status.
- **Create User for Registrar** – Add new user accounts and assign them to specific registrar entities with appropriate permissions (e.g., billing-only, technical-only).

## UX Notes

- Balance visibility is real-time and tied to transactions and invoices (see Financials).
- Only users with registry admin roles can impersonate registrars or manage pricing.
- Custom pricing entries override global default pricing per TLD and action type.
- All edits and impersonation attempts are logged in the system log for audit purposes.

## 6.1 Registrar Create/Edit Options

Registry administrators can create and update registrar profiles via the **Create Registrar** and **Edit Registrar** interfaces. While most fields are shared, certain sensitive operations are only available in edit mode.

### Registrar Details

| Field | Description |
|-------|-------------|
| **Name** | Display name of the registrar. |
| **IANA ID** | Official IANA-assigned identifier (if applicable). |
| **Email** | Primary registrar contact email (used for notifications). |
| **URL** | Public-facing URL of the registrar. |
| **WHOIS Server** | WHOIS server hostname (e.g., whois.example.com). |
| **RDAP Server** | Full RDAP base URL (e.g., https://rdap.example.com/rdap). |
| **Abuse Email** | Email for abuse complaints. |
| **Abuse Phone** | Abuse contact phone number. |

### Financial Information

| Field | Description |
|-------|-------------|
| **Account Balance** | Current live balance (read-only, shown in edit mode only). |
| **Currency** | Default billing currency. |
| **Credit Limit** | Maximum credit line extended to the registrar. |
| **Credit Threshold** | Alert threshold (e.g., 0.00 = alert when balance is negative). |
| **Threshold Type** | Fixed or percentage-based threshold logic. |
| **Company Number / VAT** | Company registration details for financial reporting. |

### Registrar Contacts

Tabs for:
- **Owner**
- **Billing**
- **Technical**
- **Abuse**

Each contact includes:
- First and Last Name
- Organization
- Address (Street, City, State, Postal Code, Country)
- Email
- Phone

The "Copy data to other contacts" toggle can be used to prefill secondary tabs.

### IP Whitelisting

- Up to 5 IPv4 or IPv6 addresses/subnets can be added.
- Required for access to EPP and Control Panel.
- Entries can be removed in edit mode.

### Registrar Users

- View and manage registrar user accounts.
- See **CLID**, **login email**, and reset **panel/EPP passwords**.
- Only visible in edit mode.

### Operational Test & Evaluation (OTE)

- Displays EPP command test statuses required for onboarding:
  - `contact:create`, `domain:create`, `host:create`, etc.
- Status shown per command: `Pending`, `Passed`, `Failed`.
- Only available in edit mode for active registrars.
- Used to verify registrar integration readiness before full launch.

### Accreditation Transfer (Edit Mode Only)

At the bottom of the edit screen, an optional irreversible action is available:

- **Registrar Accreditation Transfer**:
  - Initiates the transfer of all domain, host, and contact objects from the current registrar to a new registrar.
  - Deactivates the source registrar account after transfer.
  - Must be confirmed explicitly by an admin.
  - Visible **only in Edit mode**, not during initial creation.

### UX Notes

- Most fields are validated server-side and some (e.g., IANA ID, email) must be unique.
- Required fields are marked with an asterisk.
- "Update Registrar" applies all changes, and audit logging tracks edits for compliance.
- OTE results and IP access settings are not present during the creation flow.

---

# 7. Financials

The **Financials** section of the Namingo Control Panel provides full visibility and control over billing, payments, and financial interactions between the registry and its accredited registrars. It is designed to support both automated and manual workflows for payments, invoices, deposits, and transaction logging.

## 7.1 Account Overview

This subsection provides a chronological list of **financial events** affecting registrar balances. It is meant to serve as an audit-friendly overview of all charges, credits, and adjustments applied to each registrar’s account.

### Columns

| Column | Description |
|--------|-------------|
| **Registrar** | The registrar affected by the financial action. |
| **Date** | Timestamp of the financial event. |
| **Description** | Short description of the event (e.g., domain created, domain deleted, manual adjustment). |
| **Amount** | The amount debited or credited (typically in EUR or registry’s configured currency). |

### Features

- Fully exportable to **CSV** and **PDF** formats.
- Filterable by registrar or keyword via the **search** box.
- Supports sorting by all columns.

## 7.2 Transactions

The **Transactions** tab displays a detailed breakdown of **EPP commands** that resulted in billable events (e.g., domain creation, transfer, renewal).

### Columns

| Column | Description |
|--------|-------------|
| **Registrar** | The registrar that initiated the command. |
| **Date** | Timestamp of the command execution. |
| **Command** | The EPP operation (e.g., `create`, `transfer`, `renew`, `delete`). |
| **Domain** | The domain involved in the transaction. |
| **Length** | Duration of the registration or renewal (in months). |
| **From** / **To** | Effective start and end date of the registered/renewed period. |
| **Amount** | Fee charged for the transaction. |

### Notes

- Ideal for cross-checking domain lifecycle costs and billing events.
- Fully searchable and sortable by command type, domain name, or registrar.
- Export options (CSV, PDF) available for integration with accounting tools.

## 7.3 Invoices

The **Invoices** section lists monthly or periodic billing summaries issued to registrars. Each invoice aggregates financial activity into an official document with downloadable details.

### Columns

| Column | Description |
|--------|-------------|
| **Number** | Unique invoice number generated by the registry system. |
| **Registrar** | The recipient of the invoice. |
| **Date** | Invoice issue date. |
| **Amount** | Total amount invoiced. |
| **Actions** | A view button (👁️) that opens the invoice details in a printable layout. |

### Features

- Invoices are auto-generated based on accumulated billable events.
- Each registrar sees only their own invoices.
- Admins can access and re-issue past invoices.
- Exportable for reconciliation or PDF download.

## 7.4 Add Deposit

The **Add Deposit** interface allows registries to manually credit registrar accounts or enable registrars to fund their accounts using various payment methods.

### Registry View

- Admins can:
  - Select any active **Registrar** from a dropdown.
  - Enter the **deposit amount** (e.g., 100 EUR).
  - Optionally include a **note or reference** (e.g., "manual top-up", "invoice correction").
  - Apply the deposit instantly, increasing the registrar’s balance.

### Registrar View

- Registrars can:
  - Select a **payment method** from available options (configured by the registry), including:
    - **Credit/Debit Card**
    - **Cryptocurrency** (e.g., Bitcoin, USDT)
    - **Bank Transfer** (manual verification may apply)
  - Enter the **amount** and submit the payment.
  - Once confirmed (via webhook or manual validation), the deposit is reflected in their account.

### Features

- Secure and extensible payment flow for modern registries.
- Payment logs are tied to Account Overview and Invoices.
- Supports future integration with external billing providers (e.g., Stripe, Crypto).

## UX Notes

- Registrars only see financial records relevant to their account.
- Admins have full access to all financial data and registrar balances.
- All exports, reports, and documents respect user permissions.
- Every amount, command, and document is traceable for audit and compliance purposes.

---

# 8. Registry (Admin-Only)

The **Registry** section is restricted to users with elevated privileges (e.g., `registry administrator`, `technical operator`). It provides full control over the registry configuration, TLD management, and internal system monitoring, ensuring that all operational, policy, and technical aspects of the registry can be maintained from a unified interface.

## 8.1 Configuration

The **Registry Configuration** tab stores all fundamental settings that define how the registry behaves and identifies itself externally.

### System Settings

- **Registry Operator Name** – Name of the organization managing the registry.
- **RDAP Server** – URL to the registry’s RDAP endpoint (e.g., `https://rdap.example.tld`).
- **WHOIS Server** – Hostname of the public WHOIS server.
- **Registry Handle** – Suffix used in object identifiers across the registry (e.g., `TEST-1234`).
- **Registry Currency** – Defines the default currency for all transactions and reports (e.g., EUR).

### Operator Details

- **VAT/Company Number**
- **Contact Address (Line 1 & 2)**
- **Contact Email**
- **Contact Phone**

### Features (Toggles)

- **Require Launch Phases** – Enforces phase-based domain launches (e.g., Sunrise, Landrush).
- **Contact Validation**
  - By Phone
  - By Email
  - By Postal Mail (optional)
  
All changes are applied via the **Update Details** button.

## 8.2 TLDs

The **TLD Management** area provides complete control over all top-level domains (TLDs) in the system.

### TLD List Overview

Each row shows:
- **TLD Name**
- **Script** (e.g., ASCII, Cyrillic)
- **DNSSEC Status** – Whether the zone is signed or not.

### Actions (Per TLD)

| Icon | Action |
|------|--------|
| 🖊️ | Edit TLD Configuration |
| 🧮 | Manage Promotions |
| 📅 | Launch Phase Configuration |

### TLD Features

- **Allocation Tokens** – Define and assign custom tokens for special registrations.
- **Reserved Names** – Upload and manage names that should be blocked or reserved by policy.
- **IDN Tables** – Export or review the language script table for IDN support.
- **DNSSEC** – Toggle DNSSEC signing per TLD.
- **Premium Names** – Upload flat-file or category-based premium name lists.
- **Pricing** – Set default and override prices for domain operations (create, renew, transfer, restore, etc.).

## 8.3 Reports

The **Reports** section displays a daily summary of domain lifecycle events.

### Columns

| Column | Description |
|--------|-------------|
| **Date** | The reporting date. |
| **Total Domains** | Total number of domains under management. |
| **Created / Renewed / Transferred / Deleted / Restored Domains** | Counts of operations performed per type that day. |

### Features

- Export available in **CSV** and **PDF** formats.
- Option to generate **Registrar-Specific Statistics**.
- Useful for auditing, ICANN reports, and internal KPIs.

## 8.4 Server Health

The **Server Health** tab monitors the current operational status of backend services.

### Features

- View **status indicators** for:
  - RDAP
  - WHOIS
  - EPP
  - DNS Update Agents
  - Polling Queue
- Access system **logs**, recent failures, or latency alerts.
- Use **Clean Cache** to purge registry control panel cache (useful after settings changes or upgrades).

## 8.5 Message Queue

The **Message Queue** shows all active and archived poll messages generated for registrars.

### Use Cases

- Track events waiting to be polled via EPP (e.g., `transfer approved`, `delete requested`).
- Monitor polling delays or failed deliveries.
- Filter by registrar, domain, or message type.

## 8.6 EPP History

The **EPP History** page provides full insight into raw EPP commands and responses exchanged with the server.

### Columns

| Column | Description |
|--------|-------------|
| **Timestamp** | When the EPP command was received. |
| **Registrar** | Sender of the request. |
| **Command Type** | e.g., `create`, `update`, `poll`, `login`. |
| **Status** | Result (e.g., success, authError, syntaxError). |

- Raw XML logs are available for inspection and debugging.
- Useful for registry technical staff and compliance teams.

## 8.7 System Log

The **System Log** captures internal events, errors, and important operational notices.

### Common Entries

- CRON job failures
- API exceptions
- Misconfigured services
- Audit trail of manual actions (e.g., force deletions, overrides)

- Errors and warnings are shown with icons and color-coding.
- Logs can be filtered by severity or category.
- Helps staff proactively monitor and resolve system-level issues.

---

# 9. Support

The **Support** section of the Namingo Control Panel provides a centralized interface for handling technical, operational, or policy-related support issues. This section is available to all user roles, but the visibility and scope of tickets vary by role (registrars see only their own; admins see all).

## Purpose

- Provide real-time communication between registrars and registry staff.
- Track, escalate, and resolve issues related to domains, transfers, billing, platform bugs, or policy queries.
- Attach documentation or logs as needed for context and troubleshooting.

## Ticket Table Columns

| Column | Description |
|--------|-------------|
| **Subject** | The title of the support ticket, clickable to view conversation thread. |
| **Category** | Predefined topic classification (e.g., `Domain Transfer`, `Billing Issue`, `Registration Errors`). |
| **Status** | Current state of the ticket: `Open`, `In Progress`, `Resolved`, or `Closed`. |
| **Priority** | Severity level assigned: `Low`, `Medium`, `High`, `Urgent`. |
| **Creation Date** | Timestamp when the ticket was submitted. |
| **Actions** | View icon (👁️) opens the full ticket with messages, attachments, and history. |

## Actions and Controls

- **Create New Ticket** – Opens a form where users can:
  - Choose a **category**.
  - Set **priority**.
  - Write a **message** with optional file attachment.
- **Media Kit / Documentation Links** – Quick access to registry documentation and marketing assets.
- **Search Bar** – Filter tickets by keyword in subject or message body.
- **CSV / PDF Export** – Download ticket list for offline tracking, reporting, or auditing.

## Ticket Statuses

| Status | Description |
|--------|-------------|
| `Open` | Ticket is new and awaits review. |
| `In Progress` | Registry is actively working on the issue. |
| `Resolved` | Registry marked issue as resolved; user may reopen. |
| `Closed` | Ticket is finalized and archived. |

## UX Notes

- Tickets can include threaded replies, internal registry-only notes, and file attachments.
- Registrars cannot see tickets submitted by other registrars.
- Admins can reassign, escalate, or change the category of any ticket.
- Ticket priorities and statuses are color-coded for quick scanning.
- Ticket threads are preserved permanently for compliance and future reference.

---

# 10. User Profile

The **User Profile** section allows each control panel user to manage their personal settings, authentication methods, and security activity. It is accessible from the top-right menu and is available to all roles.

## 10.1 Details Tab

This tab shows the user's basic profile information and offers options for:

- **User Name** – Internal login name (not editable).
- **Email** – The user’s registered email address.
- **Status** – Account confirmation state (e.g., `Confirmed`).
- **Role** – User role (e.g., `Administrator`, `Registrar`, `Auditor`).

### Change Password
Users can update their password by entering the **old password** and a **new password**, followed by clicking **Update**.

### Theme Selection
Users can personalize the interface by selecting a color theme. This setting is applied immediately and saved per user.

## 10.2 Two-Factor Authentication (2FA)

This tab allows users to enable and manage Time-based One-Time Passwords (TOTP) using apps like Google Authenticator or Authy.

- Set up QR code for enrollment.
- View backup codes.
- Toggle 2FA enforcement.

## 10.3 WebAuthn

This tab lets users register **passkey-based authentication methods** (e.g., fingerprint, security keys) using the WebAuthn standard.

- Add a device (e.g., biometric scanner or hardware key).
- Remove existing authenticators.

## 10.4 Security

The **Security** tab shows an option to **log out from all other sessions** for security purposes.

## 10.5 Log

The **Log** tab provides a personal audit trail of user actions.

| Column | Description |
|--------|-------------|
| **Timestamp** | When the action occurred. |
| **Action** | Description of what was done (e.g., changed password, logged in, updated contact). |
| **IP Address** | IP from which the action was performed. |

This is useful for personal accountability and security review.

## UX Notes

- Only the currently logged-in user can access and update their profile.
- All profile settings persist across sessions and devices.
- Email and role are non-editable without admin permissions.