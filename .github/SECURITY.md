# Security Policy

## Supported Versions

Only the latest released version is supported.

[![Latest Release](https://img.shields.io/github/v/release/pushpasta/email_domains_mapper)](https://github.com/pushpasta/email_domains_mapper/releases/latest)

If you're using an older version, please update to the latest supported release.

## Reporting a Vulnerability

If you discover a security vulnerability in this project, please **do not** open a public issue.

Instead, report it privately using [GitHub Security Advisories](https://github.com/pushpasta/email_domains_mapper/security/advisories/new).

Please include as much detail as possible to help us understand and reproduce the issue, including:

- A clear description of the vulnerability
- Steps to reproduce
- Potential impact
- Affected versions
- Any suggested mitigation or fix

We will acknowledge receipt of your report and work to address it in a timely manner. Once a fix is available, we will credit reporters appropriately (if desired).

## Security Considerations

This plugin follows secure-by-default practices:

- Servers are chosen only from an explicit whitelist in `config.inc.php`
- Unknown or malformed domains are rejected before any connection is made
- Only TLS connections are allowed (`tls://` or `ssl://`), plaintext hosts are not accepted
- No fallback to default hosts
- ManageSieve is only enabled when explicitly configured per domain
