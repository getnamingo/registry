# Namingo Registry

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD, gTLD, brand and private domain registries. Written from scratch in 2023/2026, it adheres to the latest standards, ensuring a cutting-edge experience. 

Namingo is optimally designed for the 2026 ICANN new gTLD application round, providing a straightforward and easily updatable platform. Its contemporary architecture and intuitive interface make it an ideal choice for efficient and modern domain registry management.

> [!NOTE]
> Namingo **passes** ICANN OT&E RST for the `MainRSPEvaluationTest` profile, demonstrating compliance with required operational standards.

### Want to pass your own RST?

You can use our open-source [ICANN RST OT&E Test Script](https://github.com/getnamingo/registry-rst) tool to easily run your ICANN OT&E and production RST tests.

## Get Involved

We’re finalizing support for Alpine Linux, PostgreSQL, and SQLite and are looking for contributors to help test and refine these features.

We also seek assistance from gTLD operators to test Namingo in real-world environments. If you can provide access to ICANN and other relevant infrastructure, your contributions will help improve Namingo’s compatibility and reliability for registry operations.

### EPP Benchmark Summary

Namingo efficiently manages up to 150,000 domains on a VPS with 2 cores, 4GB RAM, and an 11GB SSD. On a larger setup with 8 cores, 32GB RAM, and a 125GB NVMe SSD, it scales to 1,000,000 domains, with zone generation taking approximately 6 minutes.

| **Metric**                      | 2 vCPU, 2 GB RAM, SSD | 8 vCPU, 32 GB RAM, NVMe |
|---------------------------------|-----------------------|-------------------------|
| _Domain Check_                  |                       |                         |
| Operations per Second (Ops/sec) | 217.55                | 462.58                  |
| Average Time per Operation (ms) | 4.596                 | 2.16                    |
| _Domain Info_                   |                       |                         |
| Operations per Second (Ops/sec) | 94.65                 | 225.55                  |
| Average Time per Operation (ms) | 10.57                 | 4.43                    |
| _Domain Create_                 |                       |                         |
| Operations per Second (Ops/sec) | 42.17                 | 120.62                  |
| Average Time per Operation (ms) | 23.72                 | 8.29                    |

## Features

- **ICANN Standards Support**: Supports the technical and operational requirements of both ccTLD and gTLD registries, including ICANN-related reporting, data escrow, abuse monitoring, and registration data services.

- **Control Panel**: A modern, multilingual interface for registrar and registry administration, with two-factor authentication, WebAuthn support, registrar billing, and Stripe, Adyen, and cryptocurrency payment integrations. A comprehensive API is also available for automated access to registry functions.
  
- **EPP Server**: Provides secure, standards-based communication for domain, contact, and host registration and management.

- **WHOIS Service**: Provides public domain registration data through both the traditional port 43 protocol and a web-based WHOIS interface.

- **RDAP Server**: Provides standards-based access to domain registration data through RDAP, together with an integrated web RDAP client.

- **DAS Server**: Offers a lightweight Domain Availability Service for fast and efficient domain availability checks.

- **DNS Interface**: Zone file generation with native DNSSEC signing for BIND 9 and Knot DNS, including RFC 9276-compliant NSEC3 support and optional offline KSK signing. OpenDNSSEC and NSD are supported through external signing workflows. See [Upgrading to BIND 9.20 and enabling offline KSK signing](docs/dns.md#3-upgrading-to-bind-920-and-enabling-offline-ksk-signing).

- **Database Compatibility**: Fully supports MariaDB and includes beta support for PostgreSQL, allowing operators to select the database platform that best matches their infrastructure.

- **GDPR and NIS2 Support**: Includes features designed to support GDPR and NIS2 requirements, such as contact validation, encrypted data storage, access controls, and security-focused operational processes. See the [Encryption Guide](docs/encryption.md) for implementation details.

- **Operational Automation**: Includes automation for routine registry operations such as Specification 11 abuse monitoring, transfer approval, contact and host cleanup, backups and remote uploads, domain lifecycle processing, invoice generation, email dispatch, statistics collection, TMCH and URS processing, and zone generation and signing.

- **Registry Reporting and Data Escrow**: Automates the generation and delivery of RDE deposits, LORDN files, ICANN monthly reports, invoices, and other operational registry reports.

### Optional Components

- [**Automated Registrar Onboarding**](https://github.com/getnamingo/registrar-onboarding) – Provides a complete self-service onboarding workflow for new registrars, including application forms, electronic agreement signing, and online payment of application fees. Applications can then be reviewed and approved by registry staff before account activation, eliminating manual email exchanges, document handling, and duplicate data entry.

- [**Domain Registry API**](https://github.com/getnamingo/registry-api) – Provides REST API access to domain availability checks and registry droplist data for integration with external systems and services.

- [**ntfy.sh Error Notifier**](https://github.com/getnamingo/registry-ntfy) – Monitors the registry for newly reported high-severity errors and delivers real-time push notifications through ntfy.sh.

## Documentation

Our documentation provides comprehensive guidance on installation, configuration, and initial operation, ensuring you have all the information you need to successfully manage your domain registry.

### Installation

**Minimum requirement:** a fresh VPS running Ubuntu 22.04/24.04 or Debian 12/13, with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space.
**Recommended:** 4 CPU cores, 8 GB RAM, and 50 GB hard drive space.

To get started, copy the command below and paste it into your server terminal (root access required):

```bash
bash <(curl -fsSL https://namingo.org/install.sh)
```

After installation, be sure to review all the guides in the Documentation section to complete your setup and configuration. If anything remains unclear, you can refer to the [Legacy Installation Guide](docs/install.md).

**Note for Systems with Partial or Misconfigured IPv6 Support:** If your system has partial or misconfigured IPv6 support (e.g., `ping -6 ipv6.google.com` fails), edit `/etc/gai.conf` and add or uncomment the following line `precedence ::ffff:0:0/96 100`. In the `config.php` files for WHOIS/DAS, replace `::` with `false`, or use `0.0.0.0` for EPP.

**Note for AWS/Google Cloud installations:** When installing on *AWS* or *Google Cloud*, ensure you provide the private/internal IPv4 address (e.g., `172.x.x.x` for AWS or `10.x.x.x` for Google Cloud) to the installer, rather than the public IPv4 address, as these platforms use private IPs for internal communication. For IPv6, you'll typically need to use the public IPv6 address for external-facing services. For most other cloud providers, such as DigitalOcean or Linode, you will generally need to provide the public IPv4 and public IPv6 addresses.

### Upgrade

> [!IMPORTANT]
> Upgrade scripts **must be run sequentially** without skipping versions.
>
> For example, to upgrade from **v1.0.26** to **v1.0.28**, first run the **v1.0.27** upgrade, then the **v1.0.28** upgrade.

- **v1.0.27 → v1.0.28**  
  Download and run the [`update1028.sh`](docs/update1028.sh) script.
  
- **v1.0.26 → v1.0.27**  
  Download and run the [`update1027.sh`](docs/update1027.sh) script.
  
- **v1.0.25 → v1.0.26**  
  Download and run the [`update1026.sh`](docs/update1026.sh) script.

For **older versions**, please refer to [`upgrade.md`](docs/upgrade.md).

### [Configuration Guide](docs/configuration.md) [Required]

#### [DNS Setup Guide](docs/dns.md) [Required]

#### [Registrar Payment Guide](docs/payment.md) [Required]

#### [gTLD-Specific Setup](docs/gtld.md) [gTLD Only]

#### [Database Replication](docs/replication.md) [Recommended]

#### [Data Encryption](docs/encryption.md) [Recommended]

### [First Steps Guide](docs/iog.md) [Required]

### [EPP Operations Guide](docs/epp.md) [Required]

### [Registrar FAQ](docs/faq.md) [Required]

### [System Architecture](docs/architecture.md) [Advanced]

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