<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Config\Node;
use DmLab\TypesenseInstantSearch\Model\Client\ScopedConnectionSettings;
use PHPUnit\Framework\TestCase;

class ScopedConnectionSettingsTest extends TestCase
{
    public function testOverridesTheKeyAndDelegatesEverythingElse(): void
    {
        $node = $this->createStub(Node::class);
        $inner = $this->createMock(ConnectionSettingsInterface::class);
        $inner->expects(self::never())->method('getApiKey');
        $inner->expects(self::once())->method('getNodes')->willReturn([$node]);
        $inner->expects(self::once())->method('getConnectionTimeout')->willReturn(5);
        $inner->expects(self::once())->method('getOperationTimeout')->willReturn(60);
        $inner->expects(self::once())->method('getRetryCount')->willReturn(2);
        $inner->expects(self::once())->method('getHealthCacheTtl')->willReturn(10);

        $settings = new ScopedConnectionSettings($inner, 'scoped-key');

        self::assertSame('scoped-key', $settings->getApiKey());
        self::assertSame([$node], $settings->getNodes());
        self::assertSame(5, $settings->getConnectionTimeout());
        self::assertSame(60, $settings->getOperationTimeout());
        self::assertSame(2, $settings->getRetryCount());
        self::assertSame(10, $settings->getHealthCacheTtl());
    }
}
