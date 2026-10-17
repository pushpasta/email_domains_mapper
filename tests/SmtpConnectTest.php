<?php

declare(strict_types=1);

class SmtpConnectTest extends PluginTestCase
{
    public function testSmtpHostIsSelectedForSessionDomain(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'example.com';

        $args = $this->plugin->smtp_connect([]);

        $this->assertSame('tls://smtp.example.com:587', $args['smtp_host']);
        $this->assertNotTrue($args['abort'] ?? false);
        $this->assertLogContains('SMTP host "tls://smtp.example.com:587" selected for domain "example.com"');
    }

    public function testSmtpHostIsSelectedForSecondMapping(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $args = $this->plugin->smtp_connect([]);

        $this->assertSame('ssl://smtp.plain.example.org:465', $args['smtp_host']);
    }

    public function testSmtpIsBlockedWithoutSessionDomain(): void
    {
        $args = $this->plugin->smtp_connect([]);

        $this->assertSame('tls://email-domains-mapper-disabled.invalid:587', $args['smtp_host']);
        $this->assertTrue($args['abort'] ?? false);
        $this->assertLogContains('SMTP blocked for domain "(none)"; no mapping in session');
    }

    public function testSmtpIsBlockedForUnknownSessionDomain(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'tampered.example';

        $args = $this->plugin->smtp_connect([]);

        $this->assertSame('tls://email-domains-mapper-disabled.invalid:587', $args['smtp_host']);
        $this->assertTrue($args['abort'] ?? false);
        $this->assertLogContains('SMTP blocked for domain "tampered.example"; no mapping in session');
    }

    public function testSmtpIsBlockedAfterLogout(): void
    {
        $this->authenticate('user@example.com');
        $this->plugin->login_after([]);
        $this->plugin->clear_session([]);

        $args = $this->plugin->smtp_connect([]);

        $this->assertTrue($args['abort'] ?? false);
    }
}
