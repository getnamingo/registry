# Namingo Registry

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD, gTLD, brand and private domain registries. Written from scratch in 2023/2024, it adheres to the latest standards, ensuring a cutting-edge experience. 

Namingo is optimally designed for the upcoming ICANN application round, providing a straightforward and easily updatable platform. Its contemporary architecture and intuitive interface make it an ideal choice for efficient and modern domain registry management.

## Get Involved

**Namingo** has successfully reached its initial completion milestone, thanks to the collective effort and dedication of our community. However, the journey doesn't end here! We are committed to continuously enhancing Namingo to ensure it remains the best in its class. Whether you excel in coding, have an exceptional sense of design, or are bursting with innovative concepts, your contributions are invaluable. Join us in refining and expanding Namingo's capabilities to meet and exceed the ever-evolving needs of our users.

**Namingo** efficiently manages 150,000 domains on a VPS setup (2 cores, 4GB RAM, 100GB SSD) and is compatible with Ubuntu 22.04 LTS and Debian 12, supporting MariaDB/MySQL databases. After successful QA and security assessments, we're expanding our reach. We seek volunteers for testing and creating installation guides for AlmaLinux, Alpine Linux, FreeBSD 14, and Windows, aiming to include both MariaDB/MySQL and PostgreSQL database options. This effort will broaden Namingo's adaptability and ensure it remains a comprehensive domain registry solution.

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

### In beta

- [**Automated Registrar Onboarding**](https://github.com/getnamingo/registrar-onboarding-beta) - New registrars can join by filling up a form, signing the agreement online and even paying the application fee online. Then their account is activated after check by registry staff. No more emails, Word or PDF forms or copy-paste between systems.

- [**Domain Registry API**](https://github.com/getnamingo/registry-api-beta) - Provides REST API access to domain availability checks and to the domain droplist.

## Documentation

We invite you to thoroughly review all the `.md` files within the `docs` directory to familiarize yourself with the various aspects of Namingo. These documents provide comprehensive guidance on installation, configuration, and initial operation, ensuring you have all the information you need to successfully manage your domain registry.

### Installation Instructions

#### Automated Install

For a quick and easy installation, you can use the automated install script by running the following command:

```bash
wget https://namingo.org/install.sh -O install.sh && chmod +x install.sh && ./install.sh
```

This command will download and execute the `install.sh` script from Namingo's website, which automates the installation process.

#### Manual Installation Steps

For detailed installation steps, please refer to [install.md](docs/install.md).

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