# Namingo Registry
Open-source domain registry platform. Revolutionizing ccTLD and gTLD management with Namingo.

## Introduction

Namingo is a state-of-the-art open-source domain registry platform, diligently crafted to serve ccTLD and gTLD domain registries, brand registries, and ICANN accredited registrars. It's a fusion of innovation and imagination, designed to be more than a tool; it's a vision of a world where names breathe, sing, and resonate with meaning.

Though we're still spreading our wings, our commitment to excellence ensures that Namingo is on an evolutionary journey. Gradually, we are expanding our capabilities, and before the launch of our first full version, support for a comprehensive range of services will be seamlessly integrated.

Fully primed to support gTLDs for the next ICANN application round, Namingo stands at the forefront of domain innovation, ready to embrace the next wave of digital transformation.

## Get Involved

We're on a mission to make **Namingo** the best it can be, and we need your expertise! Whether you're adept in development, have a keen eye for design, or simply brim with innovative ideas, your contribution can make a world of difference.

### Current Status:

- **WHOIS and DAS Servers**: These servers are almost ready to go! They are functional but require additional testing and security hardening to ensure reliability and safety.
  
- **EPP Server**: All primary commands including Check, Create, Info, Poll, Delete, Renew, Update, and Transfer are now added. We're currently refining and testing these features for optimal performance. Extensions like RGP and SecDNS will be available soon. As always, our focus is on security and quality.
  
- **RDAP Server**: Nearing completion. The primary focus is on formatting more data for the output. Like the others, it also requires further testing and security hardening.
  
- **Automation Scripts**: These scripts are at varying stages of development. While some are ready, they need in-depth logic testing to ensure their efficiency and accuracy.
  
- **Control Panel**: This is our most nascent component. Development is underway, and there's ample opportunity for contributors to shape its direction and functionality.

Feel free to dive into our issues to see where you can help or reach out to us directly to discuss how you can contribute.

## Features

Namingo is equipped with a comprehensive suite of features to meet the diverse needs of modern domain registries:

- **ICANN Compliant**: Robust support for both ccTLDs and gTLDs in line with ICANN requirements.
  
- **EPP Server**: Enables secure and robust communication for domain registration and management.
  
- **WHOIS Service**: Offers both port 43 access and web access, ensuring transparency in domain information retrieval.
  
- **RDAP Server**: Next-generation registration data access protocol server to provide public access to domain data.
  
- **Control Panel**: An intuitive and modern interface designed for streamlined domain management and administration. Built on the API-first principle, it ensures seamless integration and adaptability. Additionally, it supports multiple languages for enhanced user accessibility.

- **DNS Interface**: State-of-the-art zone generator tailored to support BIND, NSD, and KnotDNS, offering flexibility in DNS software choices. Seamlessly integrates with industry-leading solutions for DNSSEC signing, including OpenDNSSEC and DNS-tool, ensuring enhanced domain security and reliability.
  
- **DAS Server**: Efficient Domain Availability Service to quickly check domain availability.
  
- **API Integration**: Control panel integrated with a powerful API, facilitating direct access to the registry database.
  
- **Automation Scripts**: Ensures the continuous and smooth operation of the registry by performing routine checks and operations. Advanced scripting capabilities also facilitate the generation of RDE deposits, the creation of ICANN's monthly reports, and ensure full compliance with other ICANN gTLD requirements for streamlined regulatory adherence.

## Installation Instructions

The installation instructions have been moved to a separate file to keep things organized. For detailed installation steps, please refer to [INSTALL.md](INSTALL.md).

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/registry/issues) section of our GitHub repository.

- **GitHub Discussions**: For general discussions, ideas, or to connect with our community, visit the [Discussion](https://github.com/getnamingo/registry/discussions) page on our GitHub project.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Acknowledgements

Special thanks to **XPanel Ltd** for their inspirational work on [XPanel Registry](https://github.com/XPanel/epp).

Additionally, we extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.
- [Slim Framework 4 Starter App](https://github.com/hezecom/slim-starter) which served as the foundation for our control panel.

## Licensing

Namingo is inspired by XPanel Registry (https://github.com/XPanel/epp), which is licensed under the Apache 2.0 License, © 2017 XPanel Ltd. Namingo incorporates certain elements and parts of the code from XPanel and has been significantly rewritten. It is independently licensed under the MIT License.