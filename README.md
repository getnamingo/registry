# Namingo Registry
Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD, gTLD, brand and private domain registries. Written from scratch in 2023, it adheres to the latest standards, ensuring a cutting-edge experience. 

Namingo is optimally designed for the upcoming ICANN application round, providing a straightforward and easily updatable platform. Its contemporary architecture and intuitive interface make it an ideal choice for efficient and modern domain registry management.

## Get Involved

We're on a mission to make **Namingo** the best it can be, and we need your expertise! Whether you're adept in development, have a keen eye for design, or simply brim with innovative ideas, your contribution can make a world of difference.

### Current Status:

We've completed the core development of Namingo, including WHOIS, DAS, EPP, RDAP servers, and control panel. The system handles 150,000 domains efficiently (on a VPS with 2 cores, 4GB of RAM, and a 100GB SSD) and has passed basic QA and security tests.

Our current focus is on implementing the launch phase extension as outlined in RFC8334.

We're currently in the phase of external testing and are aware that there might be bugs or incomplete features in the project. If you're able to identify and fix any issues, we encourage you to submit a pull request. If you're not sure how to fix an issue, please don't hesitate to report it to us, and we'll work on addressing it. Your contributions and feedback are valuable in helping us improve Namingo. For any inquiries or suggestions, feel free to reach out.

## Features

Namingo is equipped with a comprehensive suite of features to meet the diverse needs of modern domain registries:

- **ICANN Compliant**: Robust support for both ccTLDs and gTLDs in line with ICANN requirements.
  
- **EPP Server**: Enables secure and robust communication for domain registration and management.
  
- **WHOIS Service**: Offers both port 43 access and web access, ensuring transparency in domain information retrieval.
  
- **RDAP Server**: Next-generation registration data access protocol server to provide public access to domain data.
  
- **Control Panel**: An intuitive and modern interface designed for streamlined domain management and administration. This control panel supports advanced security features like Two-Factor Authentication (2FA) and WebAuthn, ensuring a high level of account security for users. Notably, it includes a feature for registrars to directly make deposit payments through Stripe, providing a straightforward and secure method for managing financial transactions. Additionally, the control panel is multilingual, making it accessible to a diverse global user base.

- **DNS Interface**: State-of-the-art zone generator tailored to support BIND, NSD, and KnotDNS, offering flexibility in DNS software choices. Seamlessly integrates with industry-leading solutions for DNSSEC signing, including OpenDNSSEC and DNS-tool, ensuring enhanced domain security and reliability.
  
- **DAS Server**: Efficient Domain Availability Service to quickly check domain availability.
  
- **API Integration**: Control panel integrated with a powerful API, facilitating direct access to the registry database.

- **Database Compatibility**: Our system is versatile in its database support, accommodating both MySQL/MariaDB and PostgreSQL databases. This flexibility allows users to choose the database solution that best fits their technical preferences and existing infrastructure, ensuring seamless integration and optimal performance.

- **GDPR-Compliant Database Encryption**: Supports comprehensive database encryption to ensure GDPR compliance. For more details, see our [Encryption Guide](docs/encryption.md).
  
- **Automation Scripts**: Ensures the continuous and smooth operation of the registry by performing routine checks and operations. Advanced scripting capabilities also facilitate the generation of RDE deposits, the creation of ICANN's monthly reports, Spec 11 abuse monitoring, and ensure full compliance with other ICANN gTLD requirements for streamlined regulatory adherence.

## Installation Instructions

For detailed installation steps, please refer to [Install.md](docs/install.md).

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/registry/issues) section of our GitHub repository.

- **GitHub Discussions**: For general discussions, ideas, or to connect with our community, visit the [Discussion](https://github.com/getnamingo/registry/discussions) page on our GitHub project.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Acknowledgements

Special thanks to **XPanel Ltd** for their inspirational work on [XPanel Registry](https://github.com/XPanel/epp). Their project, licensed under the Apache 2.0 License (© 2017 XPanel Ltd), has been a key inspiration for Namingo. We've incorporated elements and certain code parts from XPanel Registry, which have been significantly rewritten in our project.

Additionally, we extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.
- [Slim Framework 4 Starter App](https://github.com/hezecom/slim-starter) which served as the foundation for our control panel.
- [Tabler](https://tabler.io/), whose elegant and intuitive interface design has greatly influenced the user experience of Namingo.

## Licensing

Namingo is licensed under the MIT License.