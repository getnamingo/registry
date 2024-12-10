# Namingo FAQ

Welcome to the FAQ for Namingo. This document is intended as a resource for registries to share with their registrars, providing answers to common questions and guidance on managing registrar accounts.

## General Information

- **Configuration Access**: All configurations of a registrar account are managed via the control panel. Registrars can choose to manage their customers through the panel or via EPP (Extensible Provisioning Protocol).
- **SSL Requirement**: Access to the registry requires an SSL certificate. Any valid SSL certificate will work.

## Tools and Modules

- **EPP Client**: We provide an EPP client in PHP available at [Tembo EPP Client](https://github.com/getpinga/tembo).
- **WHMCS Module**: For integration with WHMCS, use our module available at [WHMCS EPP RFC](https://github.com/getpinga/whmcs-epp-rfc).
- **FOSSBilling Module**: For FOSSBilling integration, refer to [FOSSBilling EPP RFC](https://github.com/getpinga/fossbilling-epp-rfc).
- **Compatibility**: All other EPP clients that support RFC-compliant EPP will also work with our system.

## Contact Management

- **Contact Types**: We support registrant, admin, tech, and billing contacts. Contacts can be updated at any time.

## EPP Extensions

Namingo's EPP service supports the following extensions:

- `urn:ietf:params:xml:ns:secDNS-1.1`
- `urn:ietf:params:xml:ns:rgp-1.0`
- `urn:ietf:params:xml:ns:launch-1.0`
- `urn:ietf:params:xml:ns:idn-1.0`
- `urn:ietf:params:xml:ns:epp:fee-1.0`
- `urn:ietf:params:xml:ns:mark-1.0`
- `urn:ietf:params:xml:ns:allocationToken-1.0`
- `https://namingo.org/epp/funds-1.0`
- `https://namingo.org/epp/identica-1.0`

## WHOIS and RDAP

- **Real-Time Updates**: WHOIS and RDAP information is updated in real-time.
- **Large Registries**: For registries with over 150,000 domains, updates are near-real-time.

## Nameserver Requirements

- **Host Objects**: Nameservers must be created as host objects in the registry before associating them with a domain.
- **IPv6 Support**: IPv6 is supported.
- **Hostname Validation**: Hostnames are not validated, but limits are as per RFCs.
- **IP Limitation**: Currently, we support one IP per nameserver.

## DNSSEC Support

- **DS and DNSKEY**: DNSSEC is supported, including both DS and DNSKEY records.
- **Supported Algorithms**:
  - RSA/SHA-256
  - ECDSA Curve P-256 with SHA-256
  - ECDSA Curve P-384 with SHA-384
  - Ed25519
  - Ed448

## Internationalized Domain Names (IDNs)

- **Support for IDNs**: IDNs are supported in Namingo.
- **Script Mixing**: Mixing scripts in a single domain name is not supported.

This FAQ should cover the essential aspects of managing a registrar account with Namingo. If you have further questions or require additional assistance, please feel free to reach out to our support team.
