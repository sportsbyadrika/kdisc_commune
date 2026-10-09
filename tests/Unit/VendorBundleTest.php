<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * deploy/vendor is the committed production dependency bundle that cPanel deploys use instead of Composer.
 * It must match composer.lock exactly — rebuild with bin/build-vendor-bundle.sh after changing dependencies.
 */
final class VendorBundleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testBundleWasBuiltFromTheCurrentLockFile(): void
    {
        $lock = $this->json('composer.lock');
        $marker = (string) file_get_contents(self::ROOT . '/deploy/vendor/BUNDLE.txt');

        self::assertStringContainsString(
            (string) $lock['content-hash'],
            $marker,
            'deploy/vendor is out of date: run bin/build-vendor-bundle.sh and commit deploy/vendor.',
        );
    }

    public function testBundleHoldsExactlyTheProductionPackages(): void
    {
        $lock = $this->json('composer.lock');
        $installed = $this->json('deploy/vendor/composer/installed.json');

        $expected = [];
        foreach ($lock['packages'] as $package) {
            $expected[$package['name']] = $package['version'];
        }
        $actual = [];
        foreach ($installed['packages'] as $package) {
            $actual[$package['name']] = $package['version'];
        }
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual, 'deploy/vendor packages differ from composer.lock (production packages only).');
        self::assertFalse($installed['dev'] ?? true, 'deploy/vendor must be built with --no-dev.');
    }

    public function testBundleAutoloaderPointsAtTheAppRelatively(): void
    {
        $static = (string) file_get_contents(self::ROOT . '/deploy/vendor/composer/autoload_static.php');

        self::assertStringContainsString("__DIR__ . '/../..' . '/app'", $static);
        self::assertStringNotContainsString('/tmp/', $static, 'the bundle must not contain absolute build paths');
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $data = json_decode((string) file_get_contents(self::ROOT . '/' . $path), true);
        self::assertIsArray($data, "$path is not valid JSON");

        return $data;
    }
}
