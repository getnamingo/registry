# Namingo Registry Architecture Guide

## Introduction

Namingo is a modern domain registry platform designed to streamline the registration, management, and discovery of domain names. Its architecture emphasizes scalability, resilience, compliance with industry standards, and a seamless user experience for both end-users and partner registrars. By leveraging efficient event-driven servers, an intuitive Control Panel, and robust backend automation, Namingo provides a high-performance environment aligned with domain industry protocols.

## Architectural Principles

**1. Modularity:** Each component (e.g., EPP, WHOIS, RDAP, DAS) is logically separated, allowing for independent scaling, maintenance, and updates without affecting the core services.

**2. Performance & Scalability:** Swoole-based servers and asynchronous event loops support high concurrency and low-latency responses, ensuring rapid query handling even under heavy loads.

**3. Standards Compliance:** Adherence to industry standards (EPP, RDAP, WHOIS) ensures interoperability and trust, enabling seamless integration with registrars and other ecosystem partners.

**4. Security & Compliance:** Built-in security measures, authentication mechanisms, and data encryption align with ICANN and local regulatory requirements for managing sensitive registration data.

**5. Automation & Observability:** Automated tasks and monitoring ensure system health, enabling proactive maintenance and capacity planning.

## High-Level Architecture Overview

```text
      Registrar                                            Registrar
           |                                                   |
           v                                                   v
   +-------+----------+                               +--------+-------+
   |  Control Panel   |                               |       EPP      |
   |  (Web Frontend)  |                               |     Server     |
   +---------+--------+                               +--------+-------+
             |                                                   |
             v                                                   v
             +------------------------+--------------------------+
                                    (DB)
                                     |
                         +-------+-------+-------+
                         |       |       |       |       
                         v       v       v       v       
                       WHOIS    RDAP    DAS  Automation
                       Srv      Srv     Srv  
                        \       |       /
                         \      |      /
                          \     |     /
                           \    |    /
                            (Clients)
```

## Core Components

### Control Panel (Administration & Registrar Portal)

**Purpose:**  
- Centralized management console for administrators and partner registrars.

**Key Capabilities:**  
- Manage domain lifecycles (registration, renewal, transfer, deletion).
- Configure domain pricing, promotions, and policy enforcement.
- Access dashboards for system health, logs, and usage metrics.

**Security:**  
- TLS/HTTPS, role-based access controls.

### Automation & Scheduling (cron.php)

**Purpose:**  
- Automate operational tasks without manual intervention.

**Key Capabilities:**  
- Automated backups, zone file generation, and maintenance tasks.
- Dynamic updates to pricing, promotional campaigns, and reserved domain lists.

**Implementation:**  
- PHP CLI scripts triggered by cron job `cron.php`.

### Specialized Servers (DAS, EPP, RDAP, WHOIS)

**Purpose:**  
- Each server adheres to specific domain-related standards and services.

**Common Traits:**  
- Swoole-based asynchronous servers for high concurrency and low latency.
- Load balancing for horizontal scaling.

**DAS (Domain Availability Service):**  
- Real-time domain availability checks.

**EPP (Extensible Provisioning Protocol) Server:**  
- Standardized domain provisioning protocol.
- Handles registrations, renewals, transfers, with full auditing and logging.

**RDAP (Registration Data Access Protocol) Server:**  
- Provides JSON-based registration data.
- Compliant with ICANN standards and supports privacy redactions.

**WHOIS Server:**  
- Traditional text-based domain query interface.
- Rate-limited and access-controlled to prevent abuse.

### Data Storage & Persistence

**Purpose:**  
- Ensures data integrity, availability, and performance.

**Components:**  
- Relational Database (MariaDB) for structured registry data.
- Read replicas and partitioning for scaling read-heavy operations.

### Security & Compliance

**Purpose:**  
- Protect sensitive registration data and ensure regulatory adherence.

**Key Measures:**  
- TLS/SSL encryption for external communication.
- Strict authentication and role-based access control.
- Auditing and logging for compliance with ICANN, GDPR, and local laws.

### Observability & Maintenance

**Purpose:**  
- Proactive monitoring, diagnostics, and rapid issue resolution.

**Practices:**  
- Metrics collection (Prometheus, Grafana).
- Real-time alerts for latency, resource usage, and error rates.

### Scalability & High Availability Strategies

- **Horizontal Scaling:** Add more server instances behind a load balancer for DAS, EPP, WHOIS, and RDAP.

- **Geo-Redundancy:** Distribute instances across multiple regions for disaster recovery.

- **Failover Mechanisms:** Automated failover to standby databases and backup DNS providers to maintain service continuity during outages.

## Conclusion

The Namingo architecture is designed to balance robustness, performance, and compliance. By segregating concerns into distinct services (DAS, EPP, RDAP, WHOIS), centralizing management through the Control Panel, and relying on automation and observability, Namingo can scale to meet evolving demands and regulatory landscapes in the domain registration ecosystem. This architecture ensures that both end-users and registrars receive a reliable, secure, and future-proof registry experience.