<?php

declare(strict_types=1);

class ManageSieveTest extends PluginTestCase
{
    private function initWithManageSieve(array $session = []): void
    {
        foreach ($session as $key => $value) {
            $_SESSION[$key] = $value;
        }

        $this->plugin = $this->initPlugin(self::baseConfig(), ['plugins' => ['managesieve']]);
    }

    public function testHooksAreNotRegisteredWithoutManagesieve(): void
    {
        $hooks = $this->plugin->registered_hooks();

        $this->assertArrayNotHasKey('managesieve_connect', $hooks);
        $this->assertArrayNotHasKey('startup', $hooks);
    }

    public function testHooksAreRegisteredWhenManagesieveIsInConfig(): void
    {
        $this->initWithManageSieve();

        $hooks = $this->plugin->registered_hooks();

        $this->assertArrayHasKey('managesieve_connect', $hooks);
        $this->assertArrayHasKey('startup', $hooks);
    }

    public function testHooksAreRegisteredWhenManagesievePluginIsLoaded(): void
    {
        rcmail::get_instance()->plugins->register('managesieve');
        $this->plugin = $this->initPlugin();

        $hooks = $this->plugin->registered_hooks();

        $this->assertArrayHasKey('managesieve_connect', $hooks);
        $this->assertArrayHasKey('startup', $hooks);
    }

    public function testAllowedHostsSetToStorageHostForDomainWithSieve(): void
    {
        $this->initWithManageSieve([
            self::SESSION_DOMAIN_KEY => 'example.com',
            'storage_host' => 'imap.example.com',
        ]);

        $this->assertSame(
            ['imap.example.com'],
            rcmail::get_instance()->config->get('managesieve_allowed_hosts')
        );
        $this->assertLogContains('ManageSieve enabled for domain "example.com"');
    }

    public function testAllowedHostsDisabledForDomainWithoutSieve(): void
    {
        $this->initWithManageSieve([
            self::SESSION_DOMAIN_KEY => 'plain.example.org',
            'storage_host' => 'imap.plain.example.org',
        ]);

        $this->assertSame(
            ['email-domains-mapper-disabled.invalid'],
            rcmail::get_instance()->config->get('managesieve_allowed_hosts')
        );
        $this->assertLogContains('ManageSieve disabled for domain "plain.example.org"');
    }

    public function testAllowedHostsNotSetWithoutStorageHost(): void
    {
        $this->initWithManageSieve([self::SESSION_DOMAIN_KEY => 'example.com']);

        $this->assertNull(rcmail::get_instance()->config->get('managesieve_allowed_hosts'));
    }

    public function testAllowedHostsNotSetWithoutManagesievePlugin(): void
    {
        $_SESSION['storage_host'] = 'imap.example.com';
        $this->plugin = $this->initPlugin();

        $this->assertNull(rcmail::get_instance()->config->get('managesieve_allowed_hosts'));
    }

    public function testManageSieveHostIsSelectedForDomainWithSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'example.com';

        $args = $this->plugin->managesieve_connect([]);

        $this->assertSame('tls://sieve.example.com:4190', $args['host']);
        $this->assertLogContains('ManageSieve host "tls://sieve.example.com:4190" selected for domain "example.com"');
    }

    public function testManageSieveIsBlockedForDomainWithoutSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $args = $this->plugin->managesieve_connect([]);

        $this->assertSame('tls://email-domains-mapper-disabled.invalid:4190', $args['host']);
        $this->assertLogContains('ManageSieve connection blocked for domain "plain.example.org"; no host configured');
    }

    public function testManageSieveIsBlockedForUnknownSessionDomain(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'tampered.example';

        $args = $this->plugin->managesieve_connect([]);

        $this->assertSame('tls://email-domains-mapper-disabled.invalid:4190', $args['host']);
    }

    public function testManageSieveIsBlockedWithoutSessionDomain(): void
    {
        $args = $this->plugin->managesieve_connect([]);

        $this->assertSame('tls://email-domains-mapper-disabled.invalid:4190', $args['host']);
    }

    public function testSettingsEntriesAreFilteredForDomainWithoutSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $args = $this->plugin->filter_managesieve_settings([
            'actions' => [
                ['domain' => 'managesieve', 'name' => 'filters'],
                ['domain' => 'managesieve', 'name' => 'vacation'],
                ['name' => 'identitys'],
            ],
        ]);

        $this->assertSame([['name' => 'identitys']], $args['actions']);
        $this->assertLogContains('hid 2 ManageSieve settings entries');
    }

    public function testSettingsEntriesAreKeptForDomainWithSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'example.com';

        $actions = [
            ['domain' => 'managesieve', 'name' => 'filters'],
            ['name' => 'identitys'],
        ];

        $args = $this->plugin->filter_managesieve_settings(['actions' => $actions]);

        $this->assertSame($actions, $args['actions']);
    }

    public function testSettingsEntriesWithoutActionsAreUntouched(): void
    {
        $this->assertSame([], $this->plugin->filter_managesieve_settings([])['actions'] ?? []);
        $this->assertSame(
            'x',
            $this->plugin->filter_managesieve_settings(['actions' => 'x'])['actions']
        );
    }

    public function testMenuEntryIsFilteredForDomainWithoutSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $content = '<a class="item" href="#" data-command="managesieve-create">Create filter</a>'
            . '<a class="item" href="#" data-command="markasjunk">Junk</a>';

        $args = $this->plugin->filter_managesieve_menu([
            'name' => 'messagemenu',
            'content' => $content,
        ]);

        $this->assertStringNotContainsString('managesieve-create', $args['content']);
        $this->assertStringContainsString('markasjunk', $args['content']);
        $this->assertLogContains('hid "Create filter" message-menu item');
    }

    public function testMenuEntryIsKeptForDomainWithSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'example.com';

        $content = '<a class="item" href="#" data-command="managesieve-create">Create filter</a>';

        $args = $this->plugin->filter_managesieve_menu([
            'name' => 'messagemenu',
            'content' => $content,
        ]);

        $this->assertSame($content, $args['content']);
    }

    public function testMenuContentOfOtherContainersIsUntouched(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $content = '<a data-command="managesieve-create">Create filter</a>';

        $args = $this->plugin->filter_managesieve_menu([
            'name' => 'taskmenu',
            'content' => $content,
        ]);

        $this->assertSame($content, $args['content']);
    }

    public function testMenuWithoutContentIsUntouched(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $this->assertSame(
            ['name' => 'messagemenu'],
            $this->plugin->filter_managesieve_menu(['name' => 'messagemenu'])
        );
    }
}
