# Namingo Registry: Registrar FAQ

Welcome to the FAQ for Namingo. This document is intended as a resource for registries to share with their registrars, providing answers to common questions and guidance on managing registrar accounts.

## General Information

- **Configuration Access**: All configurations of a registrar account are managed via the control panel. Registrars can choose to manage their customers through the panel or via EPP (Extensible Provisioning Protocol).
- **SSL Requirement**: Access to the registry requires an SSL certificate. Any valid SSL certificate will work.

## Tools and Modules

- **EPP Client**: We provide a [PHP EPP Client](https://github.com/getnamingo/epp-client). You can also use the third-party [Node.js EPP Client](https://github.com/namewiz/epp-client).
- **WHMCS Module**: For integration with WHMCS, use our module available at [WHMCS EPP Registrar](https://github.com/getnamingo/whmcs-epp-registrar).
- **FOSSBilling Module**: For FOSSBilling integration, refer to [FOSSBilling EPP Registrar](https://github.com/getnamingo/fossbilling-epp-registrar).
- **Compatibility**: All other EPP clients that support RFC-compliant EPP will also work with our system.

## Contact Management

- **Contact Types**: We support registrant, admin, tech, and billing contacts. Contacts can be updated at any time.

## EPP Extensions

Namingo's EPP service supports the following standard and proprietary extensions:

| Extension | Namespace URI | Specification |
|---|---|---|
| DNS Security (DNSSEC) | `urn:ietf:params:xml:ns:secDNS-1.1` | [RFC 5910](https://www.rfc-editor.org/rfc/rfc5910) |
| Registry Grace Period (RGP) | `urn:ietf:params:xml:ns:rgp-1.0` | [RFC 3915](https://www.rfc-editor.org/rfc/rfc3915) |
| Launch Phase Mapping | `urn:ietf:params:xml:ns:launch-1.0` | [RFC 8334](https://www.rfc-editor.org/rfc/rfc8334) |
| Registry Fee | `urn:ietf:params:xml:ns:epp:fee-1.0` | [RFC 8748](https://www.rfc-editor.org/rfc/rfc8748) |
| Allocation Token | `urn:ietf:params:xml:ns:allocationToken-1.0` | [RFC 8495](https://www.rfc-editor.org/rfc/rfc8495) |
| Login Security | `urn:ietf:params:xml:ns:epp:loginSec-1.0` | [RFC 8807](https://www.rfc-editor.org/rfc/rfc8807) |
| Unhandled Namespaces | `urn:ietf:params:xml:ns:epp:unhandled-namespaces-1.0` | [RFC 9038](https://www.rfc-editor.org/rfc/rfc9038) |
| Secure Authorization Information for Transfer | `urn:ietf:params:xml:ns:epp:secure-authinfo-transfer-1.0` | [RFC 9154](https://www.rfc-editor.org/rfc/rfc9154) |
| Registry Maintenance Notification | `urn:ietf:params:xml:ns:epp:maintenance-1.0` | [RFC 9167](https://www.rfc-editor.org/rfc/rfc9167) |
| Namingo Funds | `https://namingo.org/epp/funds-1.0` | Proprietary |
| Namingo Identica | `https://namingo.org/epp/identica-1.0` | Proprietary |

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
  - SM2SM3
  - ML-DSA-44
- **Supported Digest Types**:
  - SHA-256
  - SHA-384
  - SM3

## Internationalized Domain Names (IDNs)

- **Support for IDNs**: IDNs are supported in Namingo.
- **Script Mixing**: Mixing scripts in a single domain name is not supported.

This FAQ should cover the essential aspects of managing a registrar account with Namingo. If you have further questions or require additional assistance, please feel free to reach out to our support team.
