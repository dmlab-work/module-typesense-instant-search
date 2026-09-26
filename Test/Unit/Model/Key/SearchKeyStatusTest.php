<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyStatus;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
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
