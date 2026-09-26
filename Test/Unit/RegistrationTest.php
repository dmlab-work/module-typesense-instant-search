<?php
/**
 * Copyright © DMLab. All rights reserved.
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

    public function testModuleXmlDeclaresSequence(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertSame('DmLab_TypesenseInstantSearch', (string)$xml->module['name']);
        self::assertSame('0.0.1', (string)$xml->module['setup_version']);

        $sequence = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequence[] = (string)$module['name'];
        }

        // Core (generic client the key machinery calls) and the indexer (searchable
        // fields contract) provide what this module consumes directly, so both must
        // load first — core ahead of the indexer that builds on it.
        self::assertSame('DmLab_TypesenseCore', $sequence[0]);
        self::assertContains('DmLab_TypesenseIndexer', $sequence);
        // Magento_Search owns the stock quickSearch widget we replace in place; Csp
        // owns the policy collector seam we register our connect-src host into.
        self::assertContains('Magento_Search', $sequence);
        self::assertContains('Magento_Csp', $sequence);
    }

    public function testComposerRequiresCoreIndexerSearchAndCsp(): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true
        );

        self::assertSame('dmlab/module-typesense-instant-search', $composer['name']);
        self::assertSame('OSL-3.0', $composer['license']);
        self::assertSame('0.1.0', $composer['version']);
        self::assertSame('magento2-module', $composer['type']);

        // Core's generic client is used by this module's key machinery, so it must
        // be declared even though this module otherwise reads only.
        self::assertArrayHasKey('dmlab/module-typesense-core', $composer['require']);
        self::assertArrayHasKey('dmlab/module-typesense-indexer', $composer['require']);
        self::assertArrayHasKey('magento/module-search', $composer['require']);
        self::assertArrayHasKey('magento/module-csp', $composer['require']);
        self::assertArrayHasKey('magento/module-store', $composer['require']);
        self::assertArrayHasKey('magento/module-config', $composer['require']);

        self::assertArrayHasKey(
            'DmLab\\TypesenseInstantSearch\\',
            $composer['autoload']['psr-4']
        );
    }
}
