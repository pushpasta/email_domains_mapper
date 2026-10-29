<?php

declare(strict_types=1);


class LoggingTest extends PluginTestCase
{
    public static function invalidMappingConfig(): array
    {
        return [
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ['broken.example.org'],
                    'imap' => 'imap.example.org:143',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
                [
                    'domains' => ['example.com'],
                    'imap' => 'ssl://imap.example.com:993',
                    'smtp' => 'tls://smtp.example.com:587',
                ],
            ],
        ];
    }

    public function testLogLevelZeroDisablesAllLogging(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::invalidMappingConfig(), ['log_level' => 0]));

        $this->assertLoginAccepted($this->authenticate('user@example.com'), 'ssl://imap.example.com:993');
        $this->assertSame([], $this->logMessages());
    }

    public function testLogLevelOneLogsErrorsOnly(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(self::invalidMappingConfig());

        $this->assertLoginAccepted($this->authenticate('user@example.com'), 'ssl://imap.example.com:993');
        $this->assertLoginRejected($this->authenticate('user@unknown.example'));

        $this->assertLogContains('[error]');
        $this->assertLogNotContains('[info]');
        $this->assertLogNotContains('[debug]');
    }

    public function testLogLevelThreeLogsInfoButNotDebug(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::baseConfig(), ['log_level' => 3]));

        $this->authenticate('user@example.com');

        $this->assertLogContains('[info] email_domains_mapper: IMAP host "ssl://imap.example.com:993" selected for domain "example.com"');
        $this->assertLogNotContains('[debug]');
    }

    public function testLogLevelFourLogsDebug(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::baseConfig(), ['log_level' => 4]));

        $this->authenticate('user@example.com');

        $this->assertLogContains('[debug] email_domains_mapper: login domain "example.com" matched the whitelist');
        $this->assertLogContains('email_domains_mapper: loaded 3 mappings');
    }

    public function testStringLogLevelIsAccepted(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::baseConfig(), ['log_level' => '4']));

        $this->authenticate('user@example.com');

        $this->assertLogContains('[debug]');
    }

    public function testInvalidLogLevelFallsBackToWarning(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::baseConfig(), ['log_level' => 'banana']));

        $this->authenticate('user@example.com');

        $this->assertLogNotContains('[info]');
        $this->assertLogNotContains('[debug]');
    }

    public function testIntegerLogLevelIsClamped(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::baseConfig(), ['log_level' => 99]));

        $this->authenticate('user@example.com');

        $this->assertLogContains('[debug]');
    }

    public function testNegativeLogLevelDisablesLogging(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(array_merge(self::invalidMappingConfig(), ['log_level' => -1]));

        $this->assertSame([], $this->logMessages());
    }

    public function testConfigDiagnosticsAreLoggedOnlyOncePerSession(): void
    {
        $this->resetLogState();

        $this->plugin = $this->initPlugin(self::invalidMappingConfig());
        $this->plugin = $this->initPlugin(self::invalidMappingConfig());

        $this->assertSame(1, $this->countLogContaining('invalid mapping for domains "broken.example.org" ignored'));
    }

    public function testControlCharactersAreSanitizedInLogLines(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => ["bad\tdomain.example"],
                    'imap' => 'imap.example.org:143',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLogContains('invalid mapping for domains "bad domain.example" ignored');

        foreach ($this->logMessages() as $message) {
            $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $message), $message);
        }
    }

    public function testLongDomainsAreTruncatedInLogLines(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin([
            'log_level' => 1,
            'mappings' => [
                [
                    'domains' => [str_repeat('a', 250) . '.example'],
                    'imap' => 'imap.example.org:143',
                    'smtp' => 'tls://smtp.example.org:587',
                ],
            ],
        ]);

        $this->assertLogContains('"' . str_repeat('a', 200) . '"');
        $this->assertLogNotContains(str_repeat('a', 201));
    }

    public function testAllLinesGoToThePluginLogChannel(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(self::invalidMappingConfig());
        $this->authenticate('user@example.com');

        $this->assertNotSame([], rcube::$log_lines);

        foreach (rcube::$log_lines as [$channel]) {
            $this->assertSame('email_domains_mapper', $channel);
        }
    }

    public function testLevelTagMatchesSeverity(): void
    {
        $this->resetLogState();
        $this->plugin = $this->initPlugin(self::invalidMappingConfig());

        foreach ($this->logMessages() as $message) {
            $this->assertMatchesRegularExpression('/^\[(error|warn|info|debug)\]/', $message);
        }
    }
}
