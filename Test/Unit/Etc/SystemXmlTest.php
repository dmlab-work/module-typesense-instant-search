<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Etc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SystemXmlTest extends TestCase
{
    private \SimpleXMLElement $section;

    protected function setUp(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/adminhtml/system.xml');
        self::assertNotFalse($xml);
        $this->section = $xml->system->section;
    }

    public function testSectionAndGroupShowAtEveryScope(): void
    {
        // Without the section flags the store scope never renders the group's fields.
        self::assertSame('dmlab_typesense', (string)$this->section['id']);
        $this->assertScope($this->section, true);
        $this->assertScope($this->group(), true);
    }

    /**
     * @return array<string,array{string,bool}>
     */
    public static function fieldScopes(): array
    {
        $store = ['enabled', 'min_query_length', 'result_limit', 'debounce_ms',
            'display_title', 'display_image', 'display_price', 'display_sku'];
        $default = ['mode', 'public_host', 'key_status', 'regenerate_key'];

        $cases = [];
        foreach ($store as $id) {
            $cases[$id] = [$id, true];
        }
        foreach ($default as $id) {
            $cases[$id] = [$id, false];
        }

        return $cases;
    }

    #[DataProvider('fieldScopes')]
    public function testFieldScope(string $id, bool $storeScoped): void
    {
        $fields = $this->group()->xpath('field[@id="' . $id . '"]');
        self::assertCount(1, $fields);

        $this->assertScope($fields[0], $storeScoped);
    }

    public function testEveryFieldIsCovered(): void
    {
        $ids = array_map(static fn (\SimpleXMLElement $f): string => (string)$f['id'], $this->group()->xpath('field'));

        self::assertEqualsCanonicalizing(array_keys(self::fieldScopes()), $ids);
    }

    private function group(): \SimpleXMLElement
    {
        $groups = $this->section->xpath('group[@id="instant_search"]');
        self::assertCount(1, $groups);

        return $groups[0];
    }

    private function assertScope(\SimpleXMLElement $node, bool $storeScoped): void
    {
        $flag = $storeScoped ? '1' : '0';
        self::assertSame('1', (string)$node['showInDefault']);
        self::assertSame($flag, (string)$node['showInWebsite']);
        self::assertSame($flag, (string)$node['showInStore']);
    }
}
