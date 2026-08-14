# Namingo Registry

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Open-source domain registry platform for modern ccTLD, gTLD, brand, and private registry operations.

## Introduction

Namingo is a modern, open-source domain registry platform designed for **ccTLD, gTLD, brand, and private domain registries**. Developed from scratch for contemporary registry operations, it provides a standards-based, modular, and maintainable foundation for operating a domain name registry without dependence on legacy registry software.

The platform implements the core services required for registry operation, including **EPP, RDAP, WHOIS, DNS/DNSSEC integration, data escrow, ICANN reporting, abuse monitoring, registrar management, and registry automation**, together with a web-based administration and registrar interface.

Namingo has been developed with current ICANN gTLD technical and operational requirements in mind and has been **successfully tested against ICANN's Registry System Testing (RST) v2.0 OT&E environment for the 2026 New gTLD Program**.

Its architecture is intended to remain straightforward to deploy, operate, audit, extend, and upgrade as registry standards and policy requirements evolve. Namingo can therefore serve both established registry operators and organizations building new registry services, while remaining fully open source and under the operator's control.

## Features

- **ICANN Standards Support**: Supports the technical and operational requirements of both ccTLD and gTLD registries, including ICANN-related reporting, data escrow, abuse monitoring, and registration data services.

- **Control Panel**: A modern, multilingual interface for registrar and registry administration, with two-factor authentication, WebAuthn support, registrar billing, and Stripe, Adyen, and cryptocurrency payment integrations. A comprehensive API is also available for automated access to registry functions.
  
- **EPP Server**: Provides secure, standards-based communication for domain, contact, and host registration and management.

- **WHOIS Service**: Provides public domain registration data through both the traditional port 43 protocol and a web-based WHOIS interface.

- **RDAP Server**: Provides standards-based access to domain registration data through RDAP, together with an integrated web RDAP client.

- **DAS Server**: Offers a lightweight Domain Availability Service for fast and efficient domain availability checks.

- **DNS Interface**: Zone file generation with native DNSSEC signing for BIND 9.20 and Knot DNS 3.5, including RFC 9276-compliant NSEC3 support and optional offline KSK signing.

- **Database Compatibility**: Full support for both MariaDB and PostgreSQL, giving operators the flexibility to use either database backend.

- **GDPR and NIS2 Support**: Includes features designed to support GDPR and NIS2 requirements, such as contact validation, encrypted data storage, access controls, and security-focused operational processes. See the [Encryption Guide](docs/encryption.md) for implementation details.

- **Operational Automation**: Includes automation for routine registry operations such as Specification 11 abuse monitoring, transfer approval, contact and host cleanup, backups and remote uploads, domain lifecycle processing, invoice generation, email dispatch, statistics collection, TMCH and URS processing, and zone generation and signing.

- **Registry Reporting and Data Escrow**: Automates the generation and delivery of RDE deposits, LORDN files, ICANN monthly reports, invoices, and other operational registry reports.

### Optional Components

- [**Automated Registrar Onboarding**](https://github.com/getnamingo/registrar-onboarding) – Provides a complete self-service onboarding workflow for new registrars, including application forms, electronic agreement signing, and online payment of application fees. Applications can then be reviewed and approved by registry staff before account activation, eliminating manual email exchanges, document handling, and duplicate data entry.

- [**Domain Registry API**](https://github.com/getnamingo/registry-api) – Provides REST API access to domain availability checks and registry droplist data for integration with external systems and services.

- [**ntfy.sh Error Notifier**](https://github.com/getnamingo/registry-ntfy) – Monitors the registry for newly reported high-severity errors and delivers real-time push notifications through ntfy.sh.

## Documentation

### Installation

**Minimum requirement:** a fresh VPS or virtual machine running Ubuntu 22.04/24.04/26.04, Debian 12/13, or FreeBSD 15.1, with at least 1 CPU core, 2 GB RAM, and 10 GB of disk space.
**Recommended:** 4 CPU cores, 8 GB RAM, and 50 GB hard drive space.

To get started, copy the command below and paste it into your server terminal (root access required):

```bash
bash <(curl -fsSL https://namingo.org/install.sh)
```

After installation, be sure to review all the guides in the Documentation section to complete your setup and configuration.

**Note for Systems with Partial or Misconfigured IPv6 Support:** If your system has partial or misconfigured IPv6 support (e.g., `ping -6 ipv6.google.com` fails), edit `/etc/gai.conf` and add or uncomment the following line `precedence ::ffff:0:0/96 100`. In the `config.php` files for WHOIS/DAS, replace `::` with `false`, or use `0.0.0.0` for EPP.

**Note for AWS/Google Cloud installations:** When installing on *AWS* or *Google Cloud*, ensure you provide the private/internal IPv4 address (e.g., `172.x.x.x` for AWS or `10.x.x.x` for Google Cloud) to the installer, rather than the public IPv4 address, as these platforms use private IPs for internal communication. For IPv6, you'll typically need to use the public IPv6 address for external-facing services. For most other cloud providers, such as DigitalOcean or Linode, you will generally need to provide the public IPv4 and public IPv6 addresses.

### Configuration

#### [General Configuration](docs/configuration.md) [Required]

#### [DNS Setup](docs/dns.md) [Required]

#### [Registrar Payments](docs/payment.md) [Required]

#### [gTLD-Specific Setup](docs/gtld.md) [gTLD Only]

#### [Database Replication](docs/replication.md) [Recommended]

#### [Data Encryption](docs/encryption.md) [Recommended]

#### [First Steps Guide](docs/iog.md)

#### [EPP Operations Guide](docs/epp.md)

#### [Registrar FAQ](docs/faq.md)

#### [System Architecture](docs/architecture.md)

### Upgrade

> [!IMPORTANT]
> Upgrade scripts **must be run sequentially** without skipping versions.
>
> For example, to upgrade from **v1.0.29** to **v1.0.31**, first run the **v1.0.30** upgrade, then the **v1.0.31** upgrade.

- **v1.0.30 → v1.0.31**  
  Download and run the [`update1031.sh`](docs/update1031.sh) script.

- **v1.0.29 → v1.0.30**  
  Download and run the [`update1030.sh`](docs/update1030.sh) script.

- **v1.0.28 → v1.0.29**  
  Download and run the [`update1029.sh`](docs/update1029.sh) script.

For **older versions**, please refer to [`upgrade.md`](docs/upgrade.md).

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/registry/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Acknowledgements

Special thanks to **XPanel Ltd** for their inspirational work on [XPanel Registry](https://github.com/XPanel/epp). Their project, licensed under the Apache 2.0 License (© 2017 XPanel Ltd), has been a key inspiration for Namingo. We've incorporated elements and certain code parts from XPanel Registry, which have been significantly rewritten in our project.

Additionally, we extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.
- [Slim Framework 4 Starter App](https://github.com/hezecom/slim-starter) which served as the foundation for our control panel.
- [Tabler](https://tabler.io/), whose elegant and intuitive interface design has greatly influenced the user experience of Namingo.
- [ActiveCampaign/postmark-templates](https://github.com/ActiveCampaign/postmark-templates) and [leemunroe/responsive-html-email-template](https://github.com/leemunroe/responsive-html-email-template), for providing great email templates.

## Support This Project

If you find Namingo Registry useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Namingo Registry is licensed under the MIT License.