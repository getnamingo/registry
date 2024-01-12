# Architecture of Namingo

This document outlines the architecture of Namingo. The system is designed to efficiently manage domain registries and provide a seamless experience for registrars. It consists of several key components:

## Automation Scripts

- The system incorporates various automation scripts that perform numerous background tasks essential for the registry's operations.
- These scripts are managed and scheduled by a `cron.php` file, ensuring they run at specified times for optimal efficiency.

## Control Panel

- At the heart of our system is the Control Panel, a web-based application designed to control the entire registry system.
- It offers a user-friendly interface for administrative tasks and provides access for registrars.
- The Control Panel is central to coordinating the activities of the various servers in the system.

## Servers

The system includes several specialized servers, each serving a unique role in the management of domain registries:

### DAS Server (Domain Availability Service)

- This server is responsible for handling queries related to the availability of domain names.
- It utilizes Swoole TCP server for efficient, scalable, and concurrent connections.

### EPP Server (Extensible Provisioning Protocol)

- The EPP Server facilitates domain registration, renewal, transfer, and other related operations.
- Built on Swoole, it ensures high-performance and real-time processing of domain transactions.

### RDAP Server (Registration Data Access Protocol)

- This server provides access to registration data, complying with current industry standards for domain registration data retrieval.
- Leveraging the Swoole server, it offers fast and reliable access to registry data.

### WHOIS Server

- The WHOIS server offers a traditional protocol for querying information related to domain registration.
- Powered by Swoole's robust server capabilities, it ensures quick and accurate responses to WHOIS queries.

---

This architecture is designed to provide a comprehensive, efficient, and user-friendly system for managing domain registries. Each component plays a crucial role in the overall functionality and performance of Namingo.