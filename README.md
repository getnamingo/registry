# Namingo Registry

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD, gTLD, brand and private domain registries. Written from scratch in 2023/2024, it adheres to the latest standards, ensuring a cutting-edge experience. 

Namingo is optimally designed for the upcoming ICANN application round, providing a straightforward and easily updatable platform. Its contemporary architecture and intuitive interface make it an ideal choice for efficient and modern domain registry management.

## Get Involved

**Namingo** is now complete, thanks to our dedicated community. The journey doesn't end here, and we invite volunteers to help us continue testing and improving Namingo.

Namingo is compatible with Ubuntu 22.04/24.04 LTS and Debian 12, supporting MariaDB/MySQL databases. We are also seeking testers for new operating systems and database setups, including AlmaLinux, Alpine Linux, FreeBSD 14, and Windows, with both MariaDB/MySQL and PostgreSQL options.

Namingo efficiently manages up to 150,000 domains on a VPS setup with 2 cores, 4GB RAM, and an 11GB SSD. It can handle up to 1,000,000 domains on a more robust VPS configuration with 8 cores, 32GB RAM, and a 125GB NVMe SSD, though a few minor issues are noted in the [issues tab](https://github.com/getnamingo/registry/issues?q=is%3Aissue+is%3Aopen+label%3A%221+000+000+domains+issue%22). Zone generation for 1 million domains takes approximately 6 minutes.

Additionally, we are looking for assistance from gTLD operators to test Namingo by providing access to ICANN and other relevant systems. Your contributions are invaluable in refining and expanding Namingo's capabilities. Join us in ensuring Namingo remains the best in its class.

### EPP Benchmark Summary (per registrar)

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
  
- **DNS Interface**: State-of-the-art zone generator tailored to support BIND, NSD, and KnotDNS, offering flexibility in DNS software choices. Seamlessly integrates with industry-leading solutions for DNSSEC signing, including native, OpenDNSSEC and DNS-tool, ensuring enhanced domain security and reliability.

- **Database Compatibility**: Fully supports MySQL/MariaDB and offers beta support for PostgreSQL, providing flexibility to match users' technical needs and infrastructure for seamless integration and peak performance.

- **GDPR-Compliant Database Encryption**: Supports comprehensive database encryption to ensure GDPR compliance. For more details, see our [Encryption Guide](docs/encryption.md).
  
- **Automation Scripts**: Ensures the continuous and smooth operation of the registry by performing routine checks and operations. Included scripts for spec 11 abuse monitoring; automated approval of domain transfers; contact and host cleanup; backup processing and upload; domain lifetime status change; generation and upload of RDE deposits, LORDN file, ICANN's monthly reports, invoices; email dispatcher system; statistics generation; TMCH and URS processing; zone generator and signing.

### Optional components

- [**Automated Registrar Onboarding**](https://github.com/getnamingo/registrar-onboarding) - New registrars can join by filling up a form, signing the agreement online and even paying the application fee online. Then their account is activated after check by registry staff. No more emails, Word or PDF forms or copy-paste between systems.

- [**Domain Registry API**](https://github.com/getnamingo/registry-api) - Provides REST API access to domain availability checks and to the domain droplist.

## Documentation

Our documentation provides comprehensive guidance on installation, configuration, and initial operation, ensuring you have all the information you need to successfully manage your domain registry.

### Installation and Update Instructions

#### Installer

To begin, simply copy the command below and paste it into your server terminal. This installation process is optimized for a fresh VPS running Ubuntu 22.04/24.04 or Debian 12.

**Minimum requirement:** a VPS with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space. Please use MySQL or MariaDB.

```bash
wget https://namingo.org/install.sh -O install.sh && chmod +x install.sh && ./install.sh
```

This command will download and execute the `install.sh` script from Namingo's website, which automates the installation process.

*IPv6 Requirement:* If your system lacks IPv6 support, test with `ping -6 ipv6.google.com`. To prevent installer issues, edit `/etc/gai.conf` and uncomment or add the following line

```bash
precedence ::ffff:0:0/96 100
```

In the `config.php` files of WHOIS/DAS components make sure you replace `::` with `false` or for EPP - with `0.0.0.0`

#### Legacy Installation Guide

For those who prefer a more hands-on approach or need detailed installation instructions, refer to the [Legacy Installation Guide](docs/install.md). However, we recommend using the installer for a more streamlined experience.

#### Update Steps

- v1.0.3 to v1.0.4 - backup registry, download and run the [update104.sh](docs/update104.sh) script.

- v1.0.2 to v1.0.3 - backup registry, download and run the [update103.sh](docs/update103.sh) script.

- v1.0.1 to v1.0.2 - backup registry, download and run the [update102.sh](docs/update102.sh) script.

- v1.0.0 to v1.0.1 - backup registry, download and run the [update101.sh](docs/update101.sh) script.

#### Update Steps (from v1.0.0-RC4 to v1.0.0)

For detailed update steps, please refer to [upgrade.md](docs/upgrade.md).

### [Configuration Guide](docs/configuration.md)

#### [Database Replication](docs/replication.md)

#### [Data Encryption](docs/encryption.md)

#### [Custom Pricing per Registrar](docs/custom-registrar-pricing.md)

#### [Minimum Data Set](docs/minimum-data-set.md)

### [Initial Operation Guide](docs/iog.md)

### [FAQ](docs/faq.md)

### [Architecture of Namingo](docs/architecture.md)

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
- [leemunroe/responsive-html-email-template](https://github.com/leemunroe/responsive-html-email-template), for providing a great email template for our mailing system.

## Licensing

Namingo is licensed under the MIT License.