<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegistered(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_TypesenseInstantSearch', $paths);
    }

    public function testRegisteredPathPointsAtThisModule(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
        $path = $paths['DmLab_TypesenseInstantSearch'] ?? null;

        self::assertNotNull($path);
        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/etc/module.xml');
    }

    public function testModuleXmlHasNoSetupVersionAndDeclaresSequence(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertSame('DmLab_TypesenseInstantSearch', (string)$xml->module['name']);
        self::assertFalse(isset($xml->module['setup_version']));

        $sequence = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequence[] = (string)$module['name'];
        }

        // Core must load ahead of the indexer that builds on it.
        self::assertSame('DmLab_TypesenseCore', $sequence[0]);
        self::assertEqualsCanonicalizing(
            [
                'DmLab_TypesenseCore',
                'DmLab_TypesenseIndexer',
                'Magento_Catalog',
                'Magento_CatalogSearch',
                'Magento_Customer',
                'Magento_Search',
                'Magento_Csp',
                'Magento_Backend',
                'Magento_Config',
                'Magento_Store',
                'Magento_Tax',
                'Magento_Directory',
            ],
            $sequence
        );
    }

    public function testComposerRequiresPinnedPackages(): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('dmlab/module-typesense-instant-search', $composer['name']);
        self::assertSame('OSL-3.0', $composer['license']);
        self::assertSame('magento2-module', $composer['type']);
        self::assertArrayHasKey('version', $composer);

        $require = $composer['require'];
        self::assertEqualsCanonicalizing(
            [
                'php',
                'magento/framework',
                'magento/module-backend',
                'magento/module-catalog',
                'magento/module-catalog-search',
                'magento/module-customer',
                'magento/module-search',
                'magento/module-csp',
                'magento/module-store',
                'magento/module-config',
                'magento/module-tax',
                'magento/module-directory',
                'magento/module-ui',
                'psr/log',
                'dmlab/module-typesense-core',
                'dmlab/module-typesense-indexer',
            ],
            array_keys($require)
        );
        foreach ($require as $package => $constraint) {
            self::assertStringNotContainsString('>=', $constraint, $package);
        }
        self::assertSame('^0.2.0', $require['dmlab/module-typesense-core']);
        self::assertSame('^0.2.0', $require['dmlab/module-typesense-indexer']);

        self::assertArrayHasKey(
            'DmLab\\TypesenseInstantSearch\\',
            $composer['autoload']['psr-4']
        );
    }

    public function testComposerSupportsMagento247AndPhp82(): void
    {
        $composer = $this->composer();
        $require = $composer['require'];

        self::assertSame('~8.2.0||~8.3.0||~8.4.0||~8.5.0', $require['php']);
        self::assertSame('^2.0||^3.0', $require['psr/log']);
        self::assertSame('^11.5||^12.5', $composer['require-dev']['phpunit/phpunit']);

        // Magento 2.4.7 package versions (Mage-OS mirror metapackage).
        $magento = array_filter(
            $require,
            static fn (string $package): bool => str_starts_with($package, 'magento/'),
            ARRAY_FILTER_USE_KEY
        );
        self::assertEquals(
            [
                'magento/framework' => '^103.0.7',
                'magento/module-backend' => '^102.0.7',
                'magento/module-catalog' => '^104.0.7',
                'magento/module-catalog-search' => '^102.0.7',
                'magento/module-customer' => '^103.0.7',
                'magento/module-search' => '^101.1.7',
                'magento/module-csp' => '^100.4.6',
                'magento/module-store' => '^101.1.7',
                'magento/module-config' => '^101.2.7',
                'magento/module-tax' => '^100.4.7',
                'magento/module-directory' => '^100.4.7',
                'magento/module-ui' => '^101.2.7',
            ],
            $magento
        );
    }

    public function testComposerHasDiscoveryMetadata(): void
    {
        $composer = $this->composer();
        $repo = 'https://github.com/dmlab-work/module-typesense-instant-search';

        self::assertContains('magento2', $composer['keywords']);
        self::assertContains('typesense', $composer['keywords']);
        self::assertSame($repo, $composer['homepage']);
        self::assertSame($repo . '/issues', $composer['support']['issues']);
        self::assertSame($repo, $composer['support']['source']);
        self::assertSame('DMLab', $composer['authors'][0]['name']);
    }

    public function testComposerVersionMatchesLatestChangelogSection(): void
    {
        $changelog = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');

        self::assertSame(1, preg_match('/^## (\S+)$/m', $changelog, $match));
        self::assertSame($this->composer()['version'], $match[1]);
    }

    private function composer(): array
    {
        return json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}
