# Emails Domain Mapper

[![CI](https://github.com/pushpasta/email_domains_mapper/actions/workflows/ci.yml/badge.svg)](https://github.com/pushpasta/email_domains_mapper/actions/workflows/ci.yml)
[![Latest Release](https://img.shields.io/github/v/release/pushpasta/email_domains_mapper.svg)](https://github.com/pushpasta/email_domains_mapper/releases/latest)
[![Installs](https://img.shields.io/packagist/dt/pushpasta/email_domains_mapper.svg?label=Installs)](https://packagist.org/packages/pushpasta/email_domains_mapper)
[![Downloads](https://img.shields.io/github/downloads/pushpasta/email_domains_mapper/total.svg)](https://github.com/pushpasta/email_domains_mapper/releases)
[![License](https://img.shields.io/github/license/pushpasta/email_domains_mapper.svg)](https://github.com/pushpasta/email_domains_mapper/blob/main/LICENSE)

**Connect users to the right mail servers based on their email domain.**

`pushpasta/email_domains_mapper` is a Roundcube plugin for installations that serve multiple email domains. You configure which domains are allowed and which IMAP, SMTP, and optional ManageSieve servers each domain uses.

Users sign in with their full email address, such as `user@example.com`. The plugin looks up the domain and selects the configured servers. Addresses with unknown or malformed domains are rejected before a connection is made.

## Requirements

- **Roundcube 1.7 or newer**
- **PHP 8.1 or newer**
- The **`intl`** and **`mbstring`** PHP extensions, which are also standard Roundcube requirements
- Mail server URLs using **`tls://` or `ssl://`** — plaintext hosts are not accepted

## Installation

Install with Composer from your Roundcube installation directory:

```bash
composer require pushpasta/email_domains_mapper
```

Composer installs the plugin under `plugins/`. If prompted to enable it, answer **yes**.

The plugin's `config.inc.php` is marked as a persistent file, so subsequent `composer update` runs preserve your configuration.

## Configuration

### 1. Configure your domains and servers

In the plugin directory, copy:

```text
config.inc.php.dist → config.inc.php
```

Edit `config.inc.php` and adjust the `mappings` list for your domains and mail servers. The distribution file contains a commented example.

Each mapping contains:

- **`domains`** — one or more email domains that are allowed to sign in.
- **IMAP and SMTP server settings** — the servers used by those domains.
- **Optional `managesieve_host`** — the ManageSieve server, if you want to enable mail-filter management for those domains.

All domains in the same mapping use the same servers. To use different servers for different domains, create separate mappings.

> **Important:** Only `config.inc.php` is loaded. Editing `config.inc.php.dist` alone does not configure the plugin.

### 2. Enable the plugin

In Roundcube's main `config/config.inc.php`, add `email_domains_mapper` to your existing plugin list.

If you use `managesieve`, place `email_domains_mapper` **before** it:

```php
$config['plugins'] = [
    // Keep your other plugins here.
    'email_domains_mapper',
    'managesieve',
];
```

If Composer already enabled the plugin, check the existing entry rather than adding it again.

You can omit `'managesieve'` if you do not use it.

### 3. Configure the login form

Users must sign in with their **full email address**, not just their username.

Add this setting to Roundcube's main `config/config.inc.php` to make that requirement clear and enable email-address validation on the login form:

```php
$config['login_username_filter'] = 'email';
```

Leave `$config['username_domain']` empty so Roundcube does not automatically append a domain.

### 4. Check your setup

Try signing in with an address from one of your configured domains.

If login fails unexpectedly, check:

```text
logs/email_domains_mapper.log
```

**The plugin blocks all logins if its configuration file is missing or contains no valid mappings.** This is intentional: it prevents connections to servers that have not been explicitly configured.

## ManageSieve: mail filters per domain

ManageSieve lets users manage server-side mail filters through Roundcube's Filters interface.

You can enable it separately for each domain:

| Mapping configuration | Result |
| --- | --- |
| Includes `managesieve_host` | ManageSieve is enabled for the mapped domains. |
| Omits `managesieve_host` | ManageSieve is disabled and the Filters interface is hidden for the mapped domains. |

For the intended plugin initialization order, keep `email_domains_mapper` before `managesieve` in your plugin list. The mapper also hides the Filters interface itself, so those entries remain hidden even if the order is reversed.

## Administrator reference

### Domain matching

The plugin uses an explicit domain allowlist: only domains present in a valid mapping are accepted.

- Login names must be full `user@domain` addresses.
- Domains are matched **case-insensitively**.
- `domains` must be a **non-empty array of domain strings**, even when a mapping covers only one domain:

  ```php
  ['example.com']
  ```

- Internationalized domain names can be written in **Unicode or punycode**.
- Configured domains and login domains are normalized to punycode before matching. This normalization uses PHP's `intl` extension.

### Server selection and session handling

1. At login, the plugin checks the email domain against the configured mappings and selects the IMAP server.
2. After a successful login, it stores the matched domain in the session.
3. The `smtp_connect` and `managesieve_connect` hooks use that session domain to apply the corresponding server settings.

Unknown or malformed domains are rejected before any connection is made.

### ManageSieve integration

The plugin controls ManageSieve availability through Roundcube's `managesieve_allowed_hosts` setting for the current session:

- When the matched domain has a `managesieve_host`, the setting allows the session's storage host.
- When it does not, the setting is assigned a non-matching value.

**While this plugin is active, the `managesieve_allowed_hosts` value from the main Roundcube configuration is not used.** Configure per-domain availability through this plugin's mappings instead.

### Configuration validation and failure behavior

The configuration validator accepts only server URLs using `tls://` or `ssl://`. Plaintext hosts are rejected.

The plugin fails closed:

- A missing `config.inc.php` blocks all logins.
- A configuration with no valid mappings blocks all logins.
- Unknown or malformed login domains are rejected before a connection is made.

## FAQ

<details>
<summary>Why can't anyone log in after I installed the plugin?</summary>

This is on purpose. Logins are blocked when the plugin has nothing valid to work with. Check these things in order:

1. **The config file exists.** You need `plugins/email_domains_mapper/config.inc.php`. The `.dist` file is only a template and is never loaded.
2. **At least one mapping is valid.** If every mapping has an error, nobody can log in.
3. **Every server address is encrypted.** Each one must look like `ssl://host:port` or `tls://host:port`. Addresses without a scheme, or with a plain-text one, are rejected.
4. **Check the log.** Look in `logs/email_domains_mapper.log`. It names the mapping that failed and says why.

</details>

<details>
<summary>Everyone else can log in, but users of one domain can't. Why?</summary>

The plugin only knows which servers to use if it can read the domain from the login. Check that:

- **The user typed the full address**, such as `alice@example.com` and not just `alice`.
- **The domain is in a `domains` list**, and the mapping it's in is valid. Domains that aren't listed don't fall back to a default server.
- **`$config['username_domain']` is empty** in Roundcube's main config. If it has a value, Roundcube can change the login before the plugin sees it.

If you add the same domain to more than one mapping, only the **first** mapping is used.

</details>

<details>
<summary>Why is the Filters section missing for some users?</summary>

Filters only show up for a domain when all three of these are true:

1. The domain's mapping has a `managesieve_host`.
2. The `managesieve` plugin is enabled.
3. `email_domains_mapper` comes **before** `managesieve` in `$config['plugins']`.

If a mapping has no `managesieve_host`, filters are turned off for that domain on purpose. The plugin never uses Roundcube's global ManageSieve server as a fallback.

</details>

<details>
<summary>My <code>managesieve_allowed_hosts</code> setting is being ignored. Why?</summary>

While the plugin is active, it sets this value for each user's session. The value is the ManageSieve host of that user's domain, so each user can only reach their own domain's filter server. This is expected, and you can remove the setting from your main config.

</details>

<details>
<summary>Can I switch from a manual install to Composer?</summary>

Yes. First back up your `config.inc.php`, then delete the manually installed plugin folder. Run `composer require pushpasta/email_domains_mapper` and put your config back. Don't keep both installs, because they will conflict.

</details>

<details>
<summary>Where do I report a bug?</summary>

Open an issue on the [issue tracker](https://github.com/pushpasta/email_domains_mapper/issues). It helps a lot if you include:

- Your Roundcube and PHP versions
- The related lines from `logs/email_domains_mapper.log`, with log level `4` turned on for more detail
- Your mapping config, with real hostnames removed if you prefer

</details>

## Logging

All plugin messages are written to the **`email_domains_mapper`** log channel:

```text
logs/email_domains_mapper.log
```

The default log level is **2**, which records errors and warnings. Increase the log level in the plugin configuration for more detailed logging when troubleshooting.

Higher log levels produce more output. After troubleshooting, return the level to **2** to keep routine logging concise.

If logins fail unexpectedly, check this log first—especially for missing configuration files or invalid mappings.

## Troubleshooting

### All users are unable to sign in

Check that:

- `plugins/email_domains_mapper/config.inc.php` exists.
- You edited `config.inc.php`, not only `config.inc.php.dist`.
- At least one mapping is valid.
- Server URLs use `tls://` or `ssl://`.

Check `logs/email_domains_mapper.log` for configuration errors.

### A particular domain cannot sign in

Check that:

- The user enters their full email address.
- The domain appears in a mapping's `domains` array.
- That mapping is valid.
- `$config['username_domain']` is empty.

### Filters are missing or unavailable

Check that:

- The domain's mapping includes `managesieve_host`.
- The `managesieve` plugin is enabled.
- `email_domains_mapper` appears before `managesieve` in the plugin list.

A domain without `managesieve_host` intentionally has no Filters interface.

## Development

Clone the repository and install the dev dependencies:

```bash
composer install
```

This runs `composer hooks:install`, which points `core.hooksPath` at `.githooks/`. Run it manually if you already had the repository cloned:

```bash
composer hooks:install
```

The hooks run on every commit and push:

- `pre-commit` — `php -l` on staged PHP files.
- `pre-push` — `php -l` on all tracked PHP files and `config.inc.php.dist`.
- `commit-msg` — checks the commit title format and length.

Run the test suite with:

```bash
composer test
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the release history.
