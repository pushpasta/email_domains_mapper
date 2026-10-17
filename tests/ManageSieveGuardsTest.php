<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

class ManageSieveGuardsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = $this->initPlugin(self::baseConfig(), ['plugins' => ['managesieve']]);
    }

    public static function invalidTasks(): array
    {
        return [
            'unknown task' => ['nope'],
            'empty task' => [''],
            'integer task' => [7],
        ];
    }

    public function testStartupHookIsRegisteredWhenManagesieveEnabled(): void
    {
        $this->assertArrayHasKey('startup', $this->plugin->registered_hooks());
    }

    public function testManageSieveActionIsRedirectedForDomainWithoutSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $redirect = $this->captureRedirect([
            'action' => 'plugin.managesieve_save',
            'task' => 'settings',
        ]);

        $this->assertSame(['_task' => 'settings'], $redirect);
        $this->assertLogContains('ManageSieve action blocked for domain "plain.example.org"; no host configured');
    }

    public function testAjaxManageSieveActionShowsErrorBeforeRedirect(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';
        rcmail::get_instance()->output->ajax_call = true;

        $redirect = $this->captureRedirect([
            'action' => 'plugin.managesieve_save',
            'task' => 'settings',
        ]);

        $this->assertSame(['_task' => 'settings'], $redirect);

        $output = rcmail::get_instance()->output;
        $this->assertTrue($output->sent);
        $this->assertSame(
            [['message' => 'ManageSieve (filters) is not available for this account.', 'type' => 'error']],
            $output->messages
        );
    }

    public function testManageSieveActionIsAllowedForDomainWithSieve(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'example.com';

        $args = ['action' => 'plugin.managesieve_save', 'task' => 'settings'];

        $this->assertSame($args, $this->plugin->setup_managesieve_guards($args));
    }

    #[DataProvider('invalidTasks')]
    public function testUnknownTaskIsNormalizedToMail($task): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $redirect = $this->captureRedirect([
            'action' => 'plugin.managesieve_save',
            'task' => $task,
        ]);

        $this->assertSame(['_task' => 'mail'], $redirect);
    }

    public function testMissingTaskDefaultsToSettings(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $redirect = $this->captureRedirect(['action' => 'plugin.managesieve_save']);

        $this->assertSame(['_task' => 'settings'], $redirect);
    }

    public function testNonManageSieveActionIsNotRedirected(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $args = ['action' => 'plugin.password', 'task' => 'settings'];

        $this->assertSame($args, $this->plugin->setup_managesieve_guards($args));
    }

    public function testMissingActionIsNotRedirected(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $args = ['task' => 'settings'];

        $this->assertSame($args, $this->plugin->setup_managesieve_guards($args));
    }

    public function testGuardsAreRegisteredOnStartup(): void
    {
        $_SESSION[self::SESSION_DOMAIN_KEY] = 'plain.example.org';

        $this->plugin->setup_managesieve_guards(['action' => '']);

        $hooks = $this->plugin->registered_hooks();

        $this->assertArrayHasKey('settings_actions', $hooks);
        $this->assertArrayHasKey('template_container', $hooks);
    }

    private function captureRedirect(array $args): array
    {
        try {
            $this->plugin->setup_managesieve_guards($args);
        } catch (TestRedirectException $e) {
            return $e->redirect_to;
        }

        $this->fail('Expected a redirect that was not performed');
    }
}
