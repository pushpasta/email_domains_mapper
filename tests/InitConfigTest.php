<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

class InitConfigTest extends PluginTestCase
{
    public static function invalidServers(): array
    {
        return [
            'missing scheme' => ['imap.example.com:143'],
            'http scheme' => ['http://imap.example.com:143'],
            'disallowed scheme' => ['smtp://smtp.example.com:587'],
            'missing port' => ['ssl://imap.example.com'],
            'userinfo' => ['ssl://user@imap.example.com:993'],
            'path' => ['ssl://imap.example.com:993/folder'],
            'port zero' => ['ssl://imap.example.com:0'],
            'port above range' => ['ssl://imap.example.com:65536'],
            'leading hyphen in host' => ['ssl://-imap.example.com:993'],
            'non-string' => [42],
            'array' => [['ssl://imap.example.com:993']],
        ];
    }

    public static function malformedDomains(): array
    {
        return [
            'empty string' => [''],
            'no domain part' => ['user'],
            'missing domain' => ['user@'],
            'missing local part' => ['@example.com'],
            'two at signs' => ['a@b@example.com'],
            'space in local part' => ['user name@example.com'],
            'empty label in domain' => ['user@example..com'],
            'leading hyphen in domain' => ['user@-example.com'],
            'dotless domain' => ['user@localhost'],
            'uppercase without domain mapping' => ['user@UNKNOWN.example'],
        ];
    }

    public function testValidMappingsRegisterHooksAndAcceptLogin(): void
    {
        $hooks = $this->plugin->registered_hooks();

        $this->assertArrayHasKey('authenticate', $hooks);
        $this->assertArrayHasKey('login_after', $hooks);
        $this->assertArrayHasKey('smtp_connect', $hooks);
        $this->assertArrayHasKey('login_failed', $hooks);
        $this->assertArrayHasKey('logout_after', $hooks);
        $this->assertNotSame([], rcube::$log_lines);
        $this->assertSame('email_domains_mapper', rcube::$log_lines[0][0]);

        $this->assertLoginAccepted(
            $this->authenticate('user@example.com'),
            'ssl://imap.example.com:993'
        );
    }

    #[DataProvider('invalidServers')]
    public function testInvalidImapServerRejectsMapping(string|int|array $server): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ['blocked.example.org'],
                    'imap' => $server,
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@blocked.example.org'));
        $this->assertLogContains('invalid mapping for domains "blocked.example.org" ignored');
    }

    #[DataProvider('invalidServers')]
    public function testInvalidSmtpServerRejectsMapping(string|int|array $server): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ['blocked.example.org'],
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => $server,
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@blocked.example.org'));
        $this->assertLogContains('invalid mapping for domains "blocked.example.org" ignored');
    }

    public function testInvalidManageSieveHostRejectsWholeMapping(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ['blocked.example.org'],
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => 'tls://smtp.example.org:587',
                    'managesieve_host' => 'managesieve.example.org:4190',
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@blocked.example.org'));
        $this->assertLogContains('invalid mapping for domains "blocked.example.org" ignored');
    }

    public function testMissingImapKeyRejectsMapping(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ['blocked.example.org'],
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@blocked.example.org'));
    }

    public function testEmptyManageSieveHostKeepsMappingValid(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['sieve.example.org'],
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => 'tls://smtp.example.org:587',
                    'managesieve_host' => '',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('user@sieve.example.org'),
            'ssl://imap.example.org:993'
        );
    }

    public function testServerPortBoundariesAndUppercaseSchemeAreAccepted(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['first.example.org'],
                    'imap' => 'ssl://imap.first.example.org:1',
                    'smtp' => 'TLS://smtp.first.example.org:65535',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('user@first.example.org'),
            'ssl://imap.first.example.org:1'
        );
    }

    #[DataProvider('malformedDomains')]
    public function testMalformedLoginIsRejected(string $user): void
    {
        $this->assertLoginRejected($this->authenticate($user));
        $this->assertNull($this->sessionDomain());
    }

    public function testUnknownDomainIsRejected(): void
    {
        $result = $this->authenticate('user@unknown.example', ['host' => 'tls://attacker.example:465']);

        $this->assertLoginRejected($result);
        $this->assertSame('tls://attacker.example:465', $result['host']);
        $this->assertLogContains('login rejected for domain "unknown.example" (not in whitelist)');
    }

    public function testAcceptedLoginOverwritesSubmittedHost(): void
    {
        $result = $this->authenticate('user@example.com', ['host' => 'tls://attacker.example:465']);

        $this->assertLoginAccepted($result, 'ssl://imap.example.com:993');
        $this->assertNull($this->sessionDomain());
    }

    public function testDomainMatchIsCaseInsensitiveInConfigAndLogin(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => [' MixedCase.EXAMPLE '],
                    'imap' => 'ssl://imap.mixed.example:993',
                    'smtp' => 'tls://smtp.mixed.example:587',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('User@MIXEDcase.Example'),
            'ssl://imap.mixed.example:993'
        );
    }

    public function testUnicodeIdnDomainInConfigMatchesPunycodeLogin(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['café.example'],
                    'imap' => 'ssl://imap.cafe.example:993',
                    'smtp' => 'tls://smtp.cafe.example:587',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('user@xn--caf-dma.example'),
            'ssl://imap.cafe.example:993'
        );
    }

    public function testPunycodeDomainInConfigMatchesUnicodeLogin(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['xn--caf-dma.example'],
                    'imap' => 'ssl://imap.cafe.example:993',
                    'smtp' => 'tls://smtp.cafe.example:587',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('user@café.example'),
            'ssl://imap.cafe.example:993'
        );
    }

    public function testDuplicateDomainKeepsFirstMapping(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['dup.example.org'],
                    'imap' => 'ssl://imap.first.example.org:993',
                    'smtp' => 'tls://smtp.first.example.org:587',
                ],
                [
                    'domains' => ['dup.example.org'],
                    'imap' => 'ssl://imap.second.example.org:993',
                    'smtp' => 'tls://smtp.second.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('user@dup.example.org'),
            'ssl://imap.first.example.org:993'
        );
        $this->assertLogContains('duplicate domain "dup.example.org" ignored (first mapping wins)');
    }

    public function testEmptyDomainsListRejectsMapping(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => [],
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@example.org'));
        $this->assertLogContains('"domains" must be a non-empty array of domain strings');
    }

    public function testDomainsKeyMustBeAnArray(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => 'example.org',
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@example.org'));
        $this->assertLogContains('"domains" must be a non-empty array of domain strings');
    }

    public function testNonArrayMappingIsIgnored(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => ['broken'],
        ]);

        $this->assertLoginRejected($this->authenticate('user@example.org'));
        $this->assertLogContains('invalid mapping ignored (not an array)');
        $this->assertLogContains('no valid domain mappings configured');
    }

    public function testNonStringDomainsAreSkippedButValidOnesKept(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['good.example.org', 42, null],
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginAccepted(
            $this->authenticate('user@good.example.org'),
            'ssl://imap.example.org:993'
        );
        $this->assertLoginRejected($this->authenticate('user@42.example.org'));
        $this->assertLogContains('non-string domain in mapping ignored');
    }

    public function testEmptyAndInvalidIdnDomainsAreSkipped(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ['', str_repeat('é', 100)],
                    'imap' => 'ssl://imap.example.org:993',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLoginRejected($this->authenticate('user@example.org'));
        $this->assertLogContains('empty or invalid domain in mapping ignored');
        $this->assertLogContains('no valid domain mappings configured');
    }

    public function testNonArrayConfigRejectsAllLogins(): void
    {
        rcmail::get_instance()->config->set('email_domains_mapper', 'broken');

        $this->plugin = new email_domains_mapper();
        $this->plugin->init();

        $this->assertLoginRejected($this->authenticate('user@example.com'));
        $this->assertLogContains('no valid domain mappings configured');
    }

    public function testEmptyConfigRejectsAllLogins(): void
    {
        $this->plugin = $this->initPlugin([]);

        $this->assertLoginRejected($this->authenticate('user@example.com'));
        $this->assertLogContains('no valid domain mappings configured');
    }

    public function testNonArrayMappingsValueRejectsAllLogins(): void
    {
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => 'broken',
        ]);

        $this->assertLoginRejected($this->authenticate('user@example.com'));
        $this->assertLogContains('no valid domain mappings configured');
    }
}
