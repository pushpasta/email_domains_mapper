<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
{
    public function testChangelogExists(): void
    {
        self::assertFileExists(__DIR__ . '/../CHANGELOG.md');
    }

    public function testChangelogHasUnreleasedSection(): void
    {
        $contents = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');

        self::assertStringContainsString('## [Unreleased]', $contents);
    }

    public function testChangelogHasSectionForCurrentVersion(): void
    {
        $contents = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
        $version = email_domains_mapper::VERSION;

        self::assertMatchesRegularExpression(
            '/^## \[' . preg_quote($version, '/') . '\]/m',
            $contents,
            "CHANGELOG.md is missing a section for version {$version}"
        );
    }
}
