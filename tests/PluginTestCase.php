<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class PluginTestCase extends TestCase
{
    protected const SESSION_DOMAIN_KEY = 'email_domains_mapper_domain';
    protected const LOG_CHANNEL = 'email_domains_mapper';

    protected email_domains_mapper $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        rcube::reset_logs();
        rcmail::reset_instance();

        $this->plugin = $this->initPlugin(self::baseConfig());
    }

    protected static function baseConfig(): array
    {
        return [
            'log_level' => 4,
            'mappings' => [
                [
                    'domains' => ['example.com', 'example.net'],
                    'imap' => 'ssl://imap.example.com:993',
                    'smtp' => 'tls://smtp.example.com:587',
                    'managesieve_host' => 'tls://sieve.example.com:4190',
                ],
                [
                    'domains' => ['plain.example.org'],
                    'imap' => 'ssl://imap.plain.example.org:993',
                    'smtp' => 'ssl://smtp.plain.example.org:465',
                ],
            ],
        ];
    }

    protected function initPlugin(?array $config = null, array $extra = []): email_domains_mapper
    {
        $rcmail = rcmail::get_instance();
        $rcmail->config->set('email_domains_mapper', $config ?? self::baseConfig());

        foreach ($extra as $key => $value) {
            $rcmail->config->set($key, $value);
        }

        $plugin = new email_domains_mapper();
        $plugin->init();

        return $plugin;
    }

    protected function authenticate(string $user, array $args = []): array
    {
        return $this->plugin->authenticate(array_merge(['user' => $user], $args));
    }

    protected function sessionDomain()
    {
        return $_SESSION[self::SESSION_DOMAIN_KEY] ?? null;
    }

    protected function resetLogState(): void
    {
        rcube::reset_logs();
        unset($_SESSION['email_domains_mapper_logged']);
    }

    protected function logMessages(): array
    {
        $messages = [];

        foreach (rcube::$log_lines as [$channel, $message]) {
            if ($channel === self::LOG_CHANNEL) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    protected function assertLoginAccepted(array $result, string $imapHost): void
    {
        $this->assertNotTrue($result['abort'] ?? false);
        $this->assertSame($imapHost, $result['host']);
    }

    protected function assertLoginRejected(array $result): void
    {
        $this->assertTrue($result['abort'] ?? false);
    }

    protected function assertLogContains(string $needle): void
    {
        $this->assertStringContainsString($needle, implode("\n", $this->logMessages()));
    }

    protected function assertLogNotContains(string $needle): void
    {
        $this->assertStringNotContainsString($needle, implode("\n", $this->logMessages()));
    }

    protected function countLogContaining(string $needle): int
    {
        $count = 0;

        foreach ($this->logMessages() as $message) {
            if (str_contains($message, $needle)) {
                $count++;
            }
        }

        return $count;
    }
}
