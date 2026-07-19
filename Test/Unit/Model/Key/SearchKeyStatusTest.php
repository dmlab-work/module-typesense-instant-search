<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\Model\Key;

use MageDevGroup\TypesenseInstantSearch\Model\Key\SearchKeyStatus;
use MageDevGroup\TypesenseInstantSearch\Model\SearchKeyProvider;
use PHPUnit\Framework\TestCase;

class SearchKeyStatusTest extends TestCase
{
    public function testReportsProvisionedWhenAParentKeyExists(): void
    {
        $keyProvider = $this->createStub(SearchKeyProvider::class);
        $keyProvider->method('hasParentKey')->willReturn(true);

        $html = (new SearchKeyStatus($keyProvider))->getHtml();

        self::assertStringContainsString('provisioned', $html);
        self::assertStringNotContainsString('not provisioned', $html);
    }

    public function testReportsNotProvisionedWhenNoParentKeyExists(): void
    {
        $keyProvider = $this->createStub(SearchKeyProvider::class);
        $keyProvider->method('hasParentKey')->willReturn(false);

        $html = (new SearchKeyStatus($keyProvider))->getHtml();

        self::assertStringContainsString('not provisioned', $html);
    }
}
