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
}
