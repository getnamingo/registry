# Namingo Registry

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD, gTLD, brand and private domain registries. Written from scratch in 2023/2025, it adheres to the latest standards, ensuring a cutting-edge experience. 

Namingo is optimally designed for the 2026 ICANN new gTLD application round, providing a straightforward and easily updatable platform. Its contemporary architecture and intuitive interface make it an ideal choice for efficient and modern domain registry management.

> ✅ **Namingo passes ICANN OT&E RST for the `MainRSPEvaluationTest` profile**, demonstrating compliance with required operational standards.

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

Namingo is equipped with a comprehensive suite of features to meet the diverse needs of modern domain registries:

- **ICANN Compliant**: Robust support for both ccTLDs and gTLDs in line with ICANN requirements.

- **Control Panel**: A sleek, user-friendly interface for effortless domain management and TLD administration, enhanced with advanced security measures (2FA, WebAuthn), Stripe/Adyen/Crypto payment options for registrars, and multilingual support, ensuring global accessibility. Seamlessly integrated with a robust API for direct access to the registry database.
  
- **EPP Server**: Enables secure and robust communication for domain registration and management.
  
- **WHOIS Service**: Offers both port 43 access and web access, ensuring transparency in domain information retrieval.
  
- **RDAP Server**: Next-generation registration data access protocol server to provide public access to domain data. Also offers web RDAP client.

- **DAS Server**: Efficient Domain Availability Service to quickly check domain availability.

- **DNS Interface**: Zone file generator with native DNSSEC signing for BIND 9 and Knot DNS, including NSEC3 support (RFC 9276-compliant). Also supports OpenDNSSEC and NSD via external signing.

- **Database Compatibility**: Fully supports MySQL/MariaDB and offers beta support for PostgreSQL, providing flexibility to match users' technical needs and infrastructure for seamless integration and peak performance.

- **GDPR & NIS2 Compliance**: Namingo supports both GDPR and NIS2 requirements, including contact validation and encrypted data storage. For implementation details, see our [Encryption Guide](docs/encryption.md).

- **Automation Scripts**: Ensures the continuous and smooth operation of the registry by performing routine checks and operations. Included scripts for spec 11 abuse monitoring; automated approval of domain transfers; contact and host cleanup; backup processing and upload; domain lifetime status change; generation and upload of RDE deposits, LORDN file, ICANN's monthly reports, invoices; email dispatcher system; statistics generation; TMCH and URS processing; zone generator and signing.

### Optional components

- [**Automated Registrar Onboarding**](https://github.com/getnamingo/registrar-onboarding) - New registrars can join by filling up a form, signing the agreement online and even paying the application fee online. Then their account is activated after check by registry staff. No more emails, Word or PDF forms or copy-paste between systems.

- [**Domain Registry API**](https://github.com/getnamingo/registry-api) - Provides REST API access to domain availability checks and to the domain droplist.

- [**ntfy.sh Error Notifier**](https://github.com/getnamingo/registry-ntfy) - Checks for new high-severity errors and sends real-time push notifications via ntfy.sh.

## Documentation

Our documentation provides comprehensive guidance on installation, configuration, and initial operation, ensuring you have all the information you need to successfully manage your domain registry.

### Installation

**Minimum requirement:** a fresh VPS running Ubuntu 22.04/24.04 or Debian 12, with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space.
**Recommended:** 4 CPU cores, 8 GB RAM, and 50 GB hard drive space.

To get started, copy the command below and paste it into your server terminal (root access required):

```bash
bash <(curl -fsSL https://namingo.org/install.sh)
```

After installation, be sure to review all the guides in the Documentation section to complete your setup and configuration. If anything remains unclear, you can refer to the [Legacy Installation Guide](docs/install.md).

**Note for Systems with Partial or Misconfigured IPv6 Support:** If your system has partial or misconfigured IPv6 support (e.g., `ping -6 ipv6.google.com` fails), edit `/etc/gai.conf` and add or uncomment the following line `precedence ::ffff:0:0/96 100`. In the `config.php` files for WHOIS/DAS, replace `::` with `false`, or use `0.0.0.0` for EPP.

**Note for AWS/Google Cloud installations:** When installing on *AWS* or *Google Cloud*, ensure you provide the private/internal IPv4 address (e.g., `172.x.x.x` for AWS or `10.x.x.x` for Google Cloud) to the installer, rather than the public IPv4 address, as these platforms use private IPs for internal communication. For IPv6, you'll typically need to use the public IPv6 address for external-facing services. For most other cloud providers, such as DigitalOcean or Linode, you will generally need to provide the public IPv4 and public IPv6 addresses.

### Updating

To update, you **must run the update scripts sequentially** without skipping versions.  
For example, if you're on **v1.0.19** and the latest is **v1.0.21**, first update to **v1.0.20**, then to **v1.0.21**.

- **v1.0.20 → v1.0.21**  
  Download and run the [`update1021.sh`](docs/update1021.sh) script.

- **v1.0.19 → v1.0.20**  
  Download and run the [`update1020.sh`](docs/update1020.sh) script.

- **v1.0.18 → v1.0.19**  
  Download and run the [`update1019.sh`](docs/update1019.sh) script.

For **older versions**, please refer to [`update.md`](docs/update.md).

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

- **GitHub Discussions**: For general discussions, ideas, or to connect with our community, visit the [Discussion](https://github.com/getnamingo/registry/discussions) page on our GitHub project.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Acknowledgements

Special thanks to **XPanel Ltd** for their inspirational work on [XPanel Registry](https://github.com/XPanel/epp). Their project, licensed under the Apache 2.0 License (© 2017 XPanel Ltd), has been a key inspiration for Namingo. We've incorporated elements and certain code parts from XPanel Registry, which have been significantly rewritten in our project.

Additionally, we extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.
- [Slim Framework 4 Starter App](https://github.com/hezecom/slim-starter) which served as the foundation for our control panel.
- [Tabler](https://tabler.io/), whose elegant and intuitive interface design has greatly influenced the user experience of Namingo.
- [ActiveCampaign/postmark-templates](https://github.com/ActiveCampaign/postmark-templates) and [leemunroe/responsive-html-email-template](https://github.com/leemunroe/responsive-html-email-template), for providing great email templates.

## 💖 Support This Project

If you find Namingo Registry useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Namingo Registry is licensed under the MIT License.