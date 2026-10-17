<?php

declare(strict_types=1);

class InfoTest extends PluginTestCase
{
    public function testInfoReturnsPluginMetadata(): void
    {
        $info = email_domains_mapper::info();

        $this->assertSame('email_domains_mapper', $info['name']);
        $this->assertSame('pushpasta', $info['vendor']);
        $this->assertSame('1.0.0', $info['version']);
        $this->assertSame('GPL-3.0-or-later', $info['license']);
        $this->assertSame('https://github.com/pushpasta/email_domains_mapper', $info['uri']);
        $this->assertSame('https://github.com/pushpasta/email_domains_mapper', $info['src_uri']);
    }
}
