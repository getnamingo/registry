# Registry DNSSEC records

Namingo accepts DNSSEC delegation data over EPP secDNS 1.1 and in the control
panel. It stores and exports DS records for the parent zone. Signing the child
zone and validating its signatures remain the DNS server/operator's job.

## Accepted values

| Algorithm | Name |
|---|---|
| 8 | RSA/SHA-256 |
| 13 | ECDSAP256SHA256 |
| 14 | ECDSAP384SHA384 |
| 15 | Ed25519 |
| 16 | Ed448 |
| 17 | SM2SM3 ([RFC 9563](https://www.rfc-editor.org/rfc/rfc9563.html)) |
| 18 | ML-DSA-44 ([IANA assignment](https://www.iana.org/assignments/dns-sec-alg-numbers/)) |

| DS digest type | Name | Hexadecimal characters |
|---|---|---|
| 2 | SHA-256 | 64 |
| 4 | SHA-384 | 96 |
| 6 | SM3 | 64 |

The signing algorithm and DS digest type are separate fields. These digest
lengths do not change with the DNSKEY algorithm. Precomputed DS records do not
require local support for the signing or hashing algorithm.

## DNSKEY submissions

For `keyData`-only EPP requests, Namingo derives a DS using the operator's
`dnssec_keydata_digest_type` setting in `epp/config.php`: `2` (the unchanged
default), `4`, or `6`. EPP secDNS does not provide a digest-type selector inside
`keyData`; registrars needing a specific digest can submit `dsData` instead.
Docker deployments can set the same key in their EPP configuration override.

The control panel accepts a DS, a DNSKEY, or a matching pair. For DNSKEY-only
input, leave DS fields empty and select the desired digest in the DNSKEY section.
DNSKEY-only submissions require KSK flags `257`, as in the existing EPP policy.
The protocol is `3`. If both records are supplied, the panel checks that the DS
matches the DNSKEY before writing anything.

SM2 public keys are 64 bytes; ML-DSA-44 public keys are 1,312 bytes (1,752 Base64
characters). Keys are stored as normalized Base64 in a `TEXT` column. This is
format/length checking, not signature verification.

Computing digest type 6 requires PHP's OpenSSL extension with SM3 enabled.
Check the runtime with:

```sh
php -r 'var_dump(in_array("sm3", openssl_get_md_methods()));'
```

If SM3 is unavailable, DNSKEY-to-SM3 generation fails explicitly; it never
silently substitutes another digest. Receiving, storing, returning and exporting
a precomputed SM3 DS remains supported. Types 2 and 4 use PHP's hash extension.

The legacy `dnssec_key2ds()` helper retains its default SHA-1/SHA-256 result
shape for callers; its optional final array selects digest types, e.g. `[2, 4, 6]`.
This does not enable SHA-1 submissions in the registry.

## Upgrade

Fresh MariaDB and PostgreSQL schemas include the expanded key column. The
existing upcoming `docs/migrations/1.0.34.sh` migration also widens it for installed
registries in its post-upgrade phase. PostgreSQL's `keytag` becomes `integer`,
because a signed `smallint` cannot store the full 0–65535 DNSSEC key tag range.
The migration is safe to repeat. Apply it before accepting ML-DSA-44 DNSKEYs.
For deployments updated manually, apply the equivalent SQL before deploying:

```sql
-- MariaDB
ALTER TABLE secdns MODIFY COLUMN pubkey TEXT DEFAULT NULL;

-- PostgreSQL
ALTER TABLE secdns ALTER COLUMN pubkey TYPE text;
ALTER TABLE secdns ALTER COLUMN keytag TYPE integer;
```

No algorithm-specific changes are needed in EPP info serialization or the DS
zone writer: both already emit the stored numeric fields and complete values.
No DNS server or signer configuration is changed.

## Regression checks

Run `php tests/dnssec.php` with PHP 8.3+, ctype, PDO, SimpleXML, XMLWriter and
OpenSSL/SM3. The test checks independent digest vectors, control-panel validation,
and actual EPP create/add/remove handlers using an in-memory database double.
It does not establish a live EPP session or exercise a real SQL server/signer.

`epp/src/dnssec.php` and `cp/bootstrap/dnssec.php` are identical codec copies because
EPP and the panel are installed in separate roots. The test enforces parity.
