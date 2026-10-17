<?php

declare(strict_types=1);

/**
 * Roundcube plugin: email_domains_mapper
 *
 * @author  CodeAlDente <mail@codealdente.ovh>
 * @license GPL-3.0-or-later
 *
 * PURPOSE
 * -------
 * Chooses the IMAP, SMTP, and (optionally) ManageSieve server based on the
 * domain part of the e-mail address a user logs in with. Nothing else is
 * changed: the login name, password, SMTP credentials and all other options
 * are handled by the main Roundcube configuration.
 *
 * SECURITY MODEL
 * --------------
 * - Servers are chosen ONLY from an explicit whitelist. The whitelist is
 *   configured in the plugin config.inc.php (a sample ships as config.inc.php.dist).
 * - A host submitted by the browser is always overwritten, never trusted.
 * - Unknown or malformed domains are rejected (fail closed).
 * - There is NO fallback to a default host.
 * - Only TLS connections are allowed (ssl:// or tls://), never plain text.
 * - ManageSieve is enabled only when managesieve_host is set for the domain.
 *
 * REQUIRED MAIN CONFIG (config/config.inc.php)
 * --------------------------------------------
 *     $config['plugins'] = [
 *         'email_domains_mapper',
 *         'managesieve',
 *     ];
 *
 * The plugin overwrites the IMAP host (authenticate hook) and the SMTP
 * host (smtp_connect hook) on every connection, so no imap_host or
 * smtp_host needs to be configured globally.
 *
 * Users must log in with a full e-mail address (localpart@domain);
 * this plugin enforces that and rejects everything else.
 *
 * IMPORTANT: list email_domains_mapper before managesieve in $config['plugins'].
 * That order lets ManageSieve disable itself per domain. The plugin also hides
 * ManageSieve's UI entries itself, so it degrades safely if the order is
 * reversed (the entries stay hidden either way).
 *
 * To control this, the plugin sets managesieve_allowed_hosts for the current
 * session: to the session's storage host when the domain has a managesieve_host,
 * or to a non-matching value otherwise. While this plugin is active, a
 * managesieve_allowed_hosts value from the main config is not used.
 *
 * PLUGIN CONFIG (plugins/email_domains_mapper/config.inc.php)
 * -----------------------------------------------------------
 * Defines log_level and the domain mappings. See config.inc.php.dist in
 * the plugin folder for a full sample; copy it to config.inc.php and edit it.
 *
 * HOST FORMAT: "<scheme>://<hostname>:<port>"
 * - ssl:// = implicit TLS (IMAPS on 993, SMTPS on 465; Sieve as supported).
 * - tls:// = STARTTLS.
 * - Domains are matched case-insensitively (stored lowercase).
 * - Internationalized domains (IDN) may be given in Unicode or punycode;
 *   both are normalized to punycode.
 */
class email_domains_mapper extends rcube_plugin
{
    /**
     * Plugin metadata shown by the Roundcube plugin manager / installer.
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'name' => 'email_domains_mapper',
            'vendor' => 'pushpasta',
            'version' => '1.0.0',
            'license' => 'GPL-3.0-or-later',
            'uri' => 'https://github.com/pushpasta/email_domains_mapper',
            'src_uri' => 'https://github.com/pushpasta/email_domains_mapper',
        ];
    }

    /**
     * Session key holding the verified login domain.
     * Written only after a successful login (see login_after()).
     */
    private const SESSION_KEY = 'email_domains_mapper_domain';

    /** Session key holding log lines already emitted once (see log_once()). */
    private const SESSION_LOGGED_KEY = 'email_domains_mapper_logged';

    /** Log channel: every plugin line goes to logs/email_domains_mapper.log. */
    private const LOG_CHANNEL = 'email_domains_mapper';

    /** Log levels: 0=off, 1=errors, 2=warnings, 3=info, 4=debug. */
    private const LOG_ERROR = 1;
    private const LOG_WARNING = 2;
    private const LOG_INFO = 3;
    private const LOG_DEBUG = 4;

    /** Effective plugin log level, read from the grouped config. */
    private int $log_level = self::LOG_WARNING;

    /**
     * Allowed server URI format.
     * - scheme: ssl or tls only (no plain text)
     * - host: letters, digits, dots and hyphens; must start and end
     *   with a letter or digit (no userinfo, no paths)
     * - port: 1-5 digits (range 1-65535 is checked in is_valid_server())
     */
    private const SERVER_PATTERN = '~^(ssl|tls)://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?:(\d{1,5})$~i';

    /**
     * Validated whitelist: lowercase domain =>
     * ['imap' => ..., 'smtp' => ..., 'managesieve_host' => ?string].
     * Built once in init(). Only entries that passed validation are kept.
     *
     * @var array<string, array{imap: string, smtp: string, managesieve_host: ?string}>
     */
    private array $mappings = [];

    /**
     * Domain validated in authenticate(), waiting to be persisted by
     * login_after(). Lives for the current request only.
     */
    private ?string $pending_domain = null;

    /**
     * Plugin entry point: loads and validates config, registers hooks, and
     * gates ManageSieve by both host and settings visibility.
     */
    public function init(): void
    {
        $this->load_config();
        $rcmail = rcmail::get_instance();

        $config = $rcmail->config->get('email_domains_mapper', []);
        $config = is_array($config) ? $config : [];

        $this->log_level = $this->normalize_log_level(
            $config['log_level'] ?? self::LOG_WARNING
        );

        $mappings = $config['mappings'] ?? [];
        if (!is_array($mappings)) {
            $mappings = [];
        }

        // Validate the config once, so the hooks only handle trusted data.
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                $this->log_once(
                    self::LOG_ERROR,
                    'email_domains_mapper: invalid mapping ignored (not an array)'
                );
                continue;
            }

            $domains = $mapping['domains'] ?? null;
            if (!is_array($domains) || $domains === []) {
                $this->log_once(
                    self::LOG_ERROR,
                    'email_domains_mapper: invalid mapping ignored ("domains" must be a non-empty array of domain strings)'
                );
                continue;
            }

            $managesieve_host = $mapping['managesieve_host'] ?? null;

            if (
                !$this->is_valid_server($mapping['imap'] ?? null)
                || !$this->is_valid_server($mapping['smtp'] ?? null)
                || (
                    $managesieve_host !== null
                    && $managesieve_host !== ''
                    && !$this->is_valid_server($managesieve_host)
                )
            ) {
                $labels = [];
                foreach ($domains as $domain) {
                    if (is_scalar($domain)) {
                        $labels[] = $this->sanitize_for_log(strtolower(trim((string) $domain)));
                    }
                }
                // A broken mapping is skipped, so its domains stay rejected
                // instead of weakening the whitelist.
                $this->log_once(
                    self::LOG_ERROR,
                    'email_domains_mapper: invalid mapping for domains "'
                        . implode('", "', $labels) . '" ignored'
                );
                continue;
            }

            foreach ($domains as $domain) {
                if (!is_string($domain)) {
                    $this->log_once(
                        self::LOG_ERROR,
                        'email_domains_mapper: non-string domain in mapping ignored'
                    );
                    continue;
                }

                $domain = rcube_utils::idn_to_ascii(strtolower(trim($domain)));

                if ($domain === '') {
                    $this->log_once(
                        self::LOG_ERROR,
                        'email_domains_mapper: empty or invalid domain in mapping ignored'
                    );
                    continue;
                }

                if (isset($this->mappings[$domain])) {
                    $this->log_once(
                        self::LOG_ERROR,
                        'email_domains_mapper: duplicate domain "'
                            . $this->sanitize_for_log($domain) . '" ignored (first mapping wins)'
                    );
                    continue;
                }

                $this->mappings[$domain] = [
                    'imap' => $mapping['imap'],
                    'smtp' => $mapping['smtp'],
                    'managesieve_host' => is_string($managesieve_host)
                        && $managesieve_host !== ''
                            ? $managesieve_host
                            : null,
                ];
            }
        }

        if ($this->mappings === []) {
            $this->log_once(
                self::LOG_ERROR,
                'email_domains_mapper: no valid domain mappings configured; all logins will be rejected'
            );
        } else {
            $this->log_once(
                self::LOG_DEBUG,
                'email_domains_mapper: loaded ' . count($this->mappings) . ' mappings'
            );
        }

        // 1) Before the IMAP login: validate the address and set IMAP host.
        $this->add_hook('authenticate', [$this, 'authenticate']);

        // 2) After a successful login: remember the domain in the session.
        $this->add_hook('login_after', [$this, 'login_after']);

        // 3) On SMTP connections: set the selected host.
        $this->add_hook('smtp_connect', [$this, 'smtp_connect']);

        // 4) ManageSieve: only when the plugin is present. Set the allowed_hosts
        //    gate before managesieve::init() reads it, and hide ManageSieve UI
        //    and endpoints for domains without a configured Sieve host.
        if ($this->managesieve_enabled()) {
            $this->apply_managesieve_allowed_hosts();
            $this->add_hook('managesieve_connect', [$this, 'managesieve_connect']);
            $this->add_hook('startup', [$this, 'setup_managesieve_guards']);
        }

        // 5) Clean up the session mapping on failed login and logout.
        $this->add_hook('login_failed', [$this, 'clear_session']);
        $this->add_hook('logout_after', [$this, 'clear_session']);
    }

    /**
     * Hook: authenticate
     *
     * Validates the login name and forces the IMAP host from the whitelist.
     * Setting $args['abort'] stops the login; Roundcube then shows its
     * generic "Login failed" message, which does not reveal supported domains.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function authenticate(array $args): array
    {
        $this->pending_domain = null;

        $domain = $this->extract_domain((string) ($args['user'] ?? ''));

        if ($domain === null || !isset($this->mappings[$domain])) {
            if ($domain === null) {
                $this->log(
                    self::LOG_DEBUG,
                    'email_domains_mapper: login rejected (could not parse a domain from the login)'
                );
            } else {
                $this->log(
                    self::LOG_DEBUG,
                    'email_domains_mapper: login rejected for domain "'
                        . $this->sanitize_for_log($domain) . '" (not in whitelist)'
                );
            }
            $args['abort'] = true;
            return $args;
        }

        // Overwrite any host submitted by the browser.
        $args['host'] = $this->mappings[$domain]['imap'];

        // Remember the domain for login_after(). It is not written to the
        // session yet, because the login may still fail.
        $this->pending_domain = $domain;

        $this->log(
            self::LOG_DEBUG,
            'email_domains_mapper: login domain "' . $this->sanitize_for_log($domain)
                . '" matched the whitelist'
        );
        $this->log(
            self::LOG_INFO,
            'email_domains_mapper: IMAP host "'
                . $this->sanitize_for_log($this->mappings[$domain]['imap'])
                . '" selected for domain "' . $this->sanitize_for_log($domain) . '"'
        );

        return $args;
    }

    /**
     * Hook: login_after
     *
     * Runs only after the IMAP login succeeded. Only now is the domain
     * stored in the session, so a failed login cannot leave a mapping behind.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function login_after(array $args): array
    {
        if ($this->pending_domain !== null) {
            $domain = $this->pending_domain;
            $_SESSION[self::SESSION_KEY] = $domain;
            $this->pending_domain = null;

            $this->log(
                self::LOG_INFO,
                'email_domains_mapper: login succeeded for domain "' . $this->sanitize_for_log($domain) . '"'
            );
        } else {
            $this->log(
                self::LOG_DEBUG,
                'email_domains_mapper: login_after hook ran with no pending domain (authenticate hook did not run)'
            );
        }

        return $args;
    }

    /**
     * Hook: smtp_connect
     *
     * Sets the SMTP host for the logged-in domain. Fails closed if there is
     * no valid mapping; no default host is used.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function smtp_connect(array $args): array
    {
        $domain = $_SESSION[self::SESSION_KEY] ?? null;
        $mapping = is_string($domain) ? ($this->mappings[$domain] ?? null) : null;

        if ($mapping === null) {
            $this->log(
                self::LOG_ERROR,
                'email_domains_mapper: SMTP blocked for domain "'
                    . $this->session_domain_label() . '"; no mapping in session'
            );

            // rcube_smtp::connect() does not honor 'abort' from this hook, so
            // overwrite the host with an unroutable one to fail closed.
            $args['smtp_host'] = 'tls://email-domains-mapper-disabled.invalid:587';
            $args['abort'] = true;
            return $args;
        }

        $args['smtp_host'] = $mapping['smtp'];

        $this->log(
            self::LOG_INFO,
            'email_domains_mapper: SMTP host "' . $this->sanitize_for_log($mapping['smtp'])
                . '" selected for domain "' . $this->sanitize_for_log($domain) . '"'
        );

        return $args;
    }

    /**
     * Hook: managesieve_connect
     *
     * Uses only the host explicitly configured for the logged-in domain.
     * Never falls back to the global managesieve_host configuration.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function managesieve_connect(array $args): array
    {
        $domain = $_SESSION[self::SESSION_KEY] ?? null;
        $mapping = is_string($domain) ? ($this->mappings[$domain] ?? null) : null;
        $host = $mapping['managesieve_host'] ?? null;

        if (!is_string($host) || $host === '') {
            $this->log(
                self::LOG_ERROR,
                'email_domains_mapper: ManageSieve connection blocked for domain "'
                    . $this->session_domain_label() . '"; no host configured'
            );

            // Defense in depth if the hook is reached despite UI gating.
            $args['host'] = 'tls://email-domains-mapper-disabled.invalid:4190';
            return $args;
        }

        $args['host'] = $host;

        $this->log(
            self::LOG_INFO,
            'email_domains_mapper: ManageSieve host "' . $this->sanitize_for_log($host)
                . '" selected for domain "' . $this->sanitize_for_log($domain) . '"'
        );

        return $args;
    }

    /**
     * Sets managesieve_allowed_hosts so ManageSieve disables itself for domains
     * without a configured Sieve host.
     *
     * ManageSieve checks managesieve_allowed_hosts during its init(). An empty
     * list means "allow all" in the built-in plugin, so a deliberately
     * unmatched value disables it. This requires email_domains_mapper to be
     * listed before managesieve in $config['plugins'].
     *
     * The option is set for the current session only: to the session's storage
     * host when the domain has a Sieve host, or to a non-matching value
     * otherwise. While this plugin is active, a managesieve_allowed_hosts
     * value from the main configuration is not used.
     */
    private function apply_managesieve_allowed_hosts(): void
    {
        $domain = $_SESSION[self::SESSION_KEY] ?? null;
        $mapping = is_string($domain) ? ($this->mappings[$domain] ?? null) : null;
        $storage_host = $_SESSION['storage_host'] ?? null;

        if (!is_string($storage_host) || $storage_host === '') {
            return;
        }

        if ($mapping !== null && $mapping['managesieve_host'] !== null) {
            $allowed_hosts = [$storage_host];
            $this->log_once(self::LOG_DEBUG,
                'email_domains_mapper: ManageSieve enabled for domain "'
                    . $this->sanitize_for_log($domain) . '"');
        } else {
            $allowed_hosts = ['email-domains-mapper-disabled.invalid'];
            $this->log_once(self::LOG_DEBUG,
                'email_domains_mapper: ManageSieve disabled for domain "'
                    . $this->session_domain_label() . '"');
        }

        rcmail::get_instance()->config->set('managesieve_allowed_hosts', $allowed_hosts);
    }

    /**
     * Hook: startup
     *
     * Hides ManageSieve for domains without a configured Sieve host: removes
     * the "Filters" settings tab, the "Create filter" message-menu item, and
     * blocks ManageSieve's settings/action endpoints (including AJAX requests,
     * which receive a proper error message).
     *
     * Registered on 'startup' so the filters run after managesieve has
     * registered its settings entry and its message-menu button.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function setup_managesieve_guards(array $args): array
    {
        $this->add_hook('settings_actions', [$this, 'filter_managesieve_settings']);
        $this->add_hook('template_container', [$this, 'filter_managesieve_menu']);

        if ($this->domain_has_managesieve()) {
            return $args;
        }

        // Actions with the plugin. prefix are registered globally, so do not
        // limit this check to the settings task.
        if (!str_starts_with((string) ($args['action'] ?? ''), 'plugin.managesieve')) {
            return $args;
        }

        $this->log(self::LOG_DEBUG,
            'email_domains_mapper: ManageSieve action blocked for domain "' . $this->session_domain_label()
                . '"; no host configured');

        $task = (string) ($args['task'] ?? 'settings');
        if (!in_array($task, ['mail', 'settings', 'addressbook'], true)) {
            $task = 'mail';
        }

        $rcmail = rcmail::get_instance();
        if ($rcmail->output->ajax_call) {
            $rcmail->output->show_message(
                'ManageSieve (filters) is not available for this account.',
                'error'
            );
            $rcmail->output->send();   // AJAX reply, no connection attempt
        }
        $rcmail->output->redirect(['_task' => $task]);   // exits

        return $args;
    }

    /**
     * Hook: settings_actions
     *
     * Removes ManageSieve's settings entries when the logged-in domain has no
     * configured managesieve_host. ManageSieve tags every entry it adds
     * (Filters, Vacation, Forward) with domain = "managesieve".
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filter_managesieve_settings(array $args): array
    {
        if ($this->domain_has_managesieve() || empty($args['actions']) || !is_array($args['actions'])) {
            return $args;
        }

        $before = count($args['actions']);

        $args['actions'] = array_values(array_filter(
            $args['actions'],
            static function ($action): bool {
                return !is_array($action) || ($action['domain'] ?? null) !== 'managesieve';
            }
        ));

        if (count($args['actions']) < $before) {
            $this->log(self::LOG_DEBUG,
                'email_domains_mapper: hid ' . ($before - count($args['actions']))
                    . ' ManageSieve settings entries');
        }

        return $args;
    }

    /**
     * Hook: template_container
     *
     * ManageSieve adds its "Create filter" button to the messagemenu container.
     * Remove only that button when the logged-in domain has no Sieve host.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filter_managesieve_menu(array $args): array
    {
        if ($this->domain_has_managesieve()) {
            return $args;
        }

        if (($args['name'] ?? null) !== 'messagemenu' || !isset($args['content']) || !is_string($args['content'])) {
            return $args;
        }

        // Match the anchor by its managesieve-create command, not by its CSS
        // class or visible label, so unrelated message-menu items are untouched.
        $filtered = preg_replace(
            '~<a\b(?=[^>]*\bmanagesieve-create\b)[^>]*>.*?</a>\s*~is',
            '',
            $args['content']
        );

        if (is_string($filtered)) {
            if ($filtered !== $args['content']) {
                $this->log(self::LOG_DEBUG,
                    'email_domains_mapper: hid "Create filter" message-menu item');
            }
            $args['content'] = $filtered;
        }

        return $args;
    }

    /** True when the logged-in domain has a configured managesieve_host. */
    private function domain_has_managesieve(): bool
    {
        $domain = $_SESSION[self::SESSION_KEY] ?? null;
        $mapping = is_string($domain) ? ($this->mappings[$domain] ?? null) : null;

        return $mapping !== null && $mapping['managesieve_host'] !== null;
    }

    /**
     * True when the managesieve plugin is loaded or configured. Roundcube
     * instantiates all plugins before running their init(), so get_plugin()
     * already answers this during our init(); the config list is a fallback.
     */
    private function managesieve_enabled(): bool
    {
        $rcmail = rcmail::get_instance();

        if ($rcmail->plugins->get_plugin('managesieve') !== null) {
            return true;
        }

        return in_array('managesieve', (array) $rcmail->config->get('plugins', []), true);
    }

    /**
     * Hooks: login_failed, logout_after
     *
     * Removes the stored domain mapping so no stale state remains.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function clear_session(array $args): array
    {
        $domain = $_SESSION[self::SESSION_KEY] ?? null;

        unset($_SESSION[self::SESSION_KEY]);
        $this->pending_domain = null;

        if (is_string($domain) && $domain !== '') {
            $this->log(
                self::LOG_DEBUG,
                'email_domains_mapper: session mapping cleared for domain "'
                    . $this->sanitize_for_log($domain) . '"'
            );
        }

        return $args;
    }

    /**
     * Writes a message only when its severity is enabled by log_level.
     * The configured level is a threshold: level N includes errors and all
     * less-severe messages up to N; level 0 disables all plugin logging.
     * Each line is prefixed with a level tag such as "[info]" or "[warn]".
     */
    private function log(int $level, string $message): void
    {
        if ($this->log_level >= $level && $this->log_level > 0) {
            rcube::write_log(self::LOG_CHANNEL, $this->level_tag($level) . ' ' . $message);
        }
    }

    /** Bracket tag for a log level, e.g. "[warn]". */
    private function level_tag(int $level): string
    {
        return match ($level) {
            self::LOG_ERROR => '[error]',
            self::LOG_WARNING => '[warn]',
            self::LOG_INFO => '[info]',
            self::LOG_DEBUG => '[debug]',
            default => '[log]',
        };
    }

    /**
     * Like log(), but writes a given line at most once per session. Used for
     * init/config diagnostics that would otherwise repeat on every request.
     */
    private function log_once(int $level, string $message): void
    {
        if ($this->log_level < $level || $this->log_level <= 0) {
            return;
        }

        $hash = md5((string) $level . "\0" . $message);
        $seen = $_SESSION[self::SESSION_LOGGED_KEY] ?? [];

        if (!is_array($seen)) {
            $seen = [];
        }

        if (isset($seen[$hash])) {
            return;
        }

        if (count($seen) < 100) {
            $seen[$hash] = true;
            $_SESSION[self::SESSION_LOGGED_KEY] = $seen;
        }

        $this->log($level, $message);
    }

    /**
     * Makes a value safe to embed in a single log line: control characters
     * (which could forge log entries) are replaced by spaces, then the result
     * is trimmed and length-capped. Returns an empty string for non-strings.
     */
    private function sanitize_for_log(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, 200);
    }

    /**
     * The logged-in domain, sanitized for logging, or "(none)" when unset.
     */
    private function session_domain_label(): string
    {
        $domain = $_SESSION[self::SESSION_KEY] ?? null;
        $domain = is_string($domain) ? $this->sanitize_for_log($domain) : '';

        return $domain !== '' ? $domain : '(none)';
    }

    /** Normalize malformed settings to the safe default warning level. */
    private function normalize_log_level(mixed $value): int
    {
        if (
            is_int($value)
            || (is_string($value) && preg_match('/^[0-4]$/', $value) === 1)
        ) {
            return max(0, min(4, (int) $value));
        }

        return self::LOG_WARNING;
    }

    /**
     * Extracts the lowercase (punycode) domain from a login name.
     *
     * Accepts exactly one valid e-mail address and returns null otherwise.
     * A single "@" is required, which prevents tricks like "a@b@evil.com".
     * Internationalized domains are normalized to punycode, and lowercasing
     * makes the lookup case-insensitive, as DNS is.
     */
    private function extract_domain(string $user): ?string
    {
        $user = trim($user);

        if (substr_count($user, '@') !== 1) {
            return null;
        }

        $user = rcube_utils::idn_to_ascii($user);

        if ($user === '' || filter_var($user, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return strtolower(substr($user, (int) strrpos($user, '@') + 1));
    }

    /**
     * Validates a server URI such as "ssl://imap.example.com:993".
     *
     * Enforces a TLS-only scheme, a plain hostname, and a port in the
     * range 1-65535.
     */
    private function is_valid_server(mixed $uri): bool
    {
        if (!is_string($uri) || preg_match(self::SERVER_PATTERN, $uri, $matches) !== 1) {
            return false;
        }

        $port = (int) $matches[2];

        return $port >= 1 && $port <= 65535;
    }
}
