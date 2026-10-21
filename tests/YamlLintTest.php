<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class YamlLintTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function yamlFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../.github', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!in_array(strtolower($file->getExtension()), ['yml', 'yaml'], true)) {
                continue;
            }
            $key = str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__ . '/../')));
            $files[$key] = [$file->getPathname()];
        }

        ksort($files);

        return $files;
    }

    #[DataProvider('yamlFiles')]
    public function testYamlFilesParse(string $path): void
    {
        try {
            Yaml::parseFile($path);
        } catch (ParseException $e) {
            self::fail(sprintf('YAML syntax error in %s: %s', $path, $e->getMessage()));
        }

        self::assertTrue(true);
    }

    public function testYamlFileSetIsNotEmpty(): void
    {
        $files = self::yamlFiles();

        self::assertNotEmpty($files, 'No YAML files found under .github/');
        self::assertArrayHasKey('.github/workflows/ci.yml', $files);
        self::assertArrayHasKey('.github/workflows/release.yml', $files);
        self::assertArrayHasKey('.github/workflows/packaging.yml', $files);
    }
}
