# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-29

### Added
- Domain-based selection of IMAP, SMTP and optional ManageSieve hosts from an explicit, case-insensitive domain allowlist.
- Automatic IDN (Unicode/punycode) normalization for configured and login domains.
- Fail-closed authentication: unknown, malformed or duplicate domains and servers are rejected before any connection is made.
- Per-domain ManageSieve enable/disable, including settings, menu and endpoint gating.
- Configurable logging to `logs/email_domains_mapper.log` with five verbosity levels, sanitization and per-session de-duplication.
- Composer installation; `config.inc.php` is preserved across updates.
- Comprehensive unit test suite covering configuration validation, routing, session handling and ManageSieve guards.
