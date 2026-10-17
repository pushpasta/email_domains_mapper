<?php

declare(strict_types=1);

class LoginSessionTest extends PluginTestCase
{
    public function testLoginAfterStoresPendingDomain(): void
    {
        $this->assertLoginAccepted($this->authenticate('user@example.com'), 'ssl://imap.example.com:993');
        $this->assertNull($this->sessionDomain());

        $this->plugin->login_after([]);

        $this->assertSame('example.com', $this->sessionDomain());
        $this->assertLogContains('login succeeded for domain "example.com"');
    }

    public function testLoginAfterTwiceKeepsSessionAndClearsPending(): void
    {
        $this->authenticate('user@example.com');
        $this->plugin->login_after([]);
        $this->plugin->login_after([]);

        $this->assertSame('example.com', $this->sessionDomain());
        $this->assertLogContains('login_after hook ran with no pending domain');
    }

    public function testLoginAfterWithoutAuthenticateStoresNothing(): void
    {
        $this->plugin->login_after([]);

        $this->assertNull($this->sessionDomain());
        $this->assertLogContains('login_after hook ran with no pending domain');
    }

    public function testRejectedLoginNeverReachesSession(): void
    {
        $this->assertLoginRejected($this->authenticate('user@unknown.example'));
        $this->plugin->login_after([]);

        $this->assertNull($this->sessionDomain());
    }

    public function testClearSessionClearsPendingDomainBeforeLoginAfter(): void
    {
        $this->assertLoginAccepted($this->authenticate('user@example.com'), 'ssl://imap.example.com:993');

        $this->plugin->clear_session([]);
        $this->plugin->login_after([]);

        $this->assertNull($this->sessionDomain());
        $this->assertLogNotContains('session mapping cleared');
    }

    public function testClearSessionRemovesStoredDomain(): void
    {
        $this->authenticate('user@example.com');
        $this->plugin->login_after([]);
        $this->assertSame('example.com', $this->sessionDomain());

        $this->plugin->clear_session([]);

        $this->assertNull($this->sessionDomain());
        $this->assertLogContains('session mapping cleared for domain "example.com"');
    }

    public function testClearSessionWithoutStateIsSafe(): void
    {
        $args = $this->plugin->clear_session(['keep' => 'value']);

        $this->assertSame(['keep' => 'value'], $args);
        $this->assertNull($this->sessionDomain());
    }
}
