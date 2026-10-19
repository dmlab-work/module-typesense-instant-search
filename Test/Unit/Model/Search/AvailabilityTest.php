<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Search;

use DmLab\TypesenseInstantSearch\Model\Search\Availability;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AvailabilityTest extends TestCase
{
    /** @var CacheInterface&MockObject */
    private $cache;

    private Availability $availability;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->availability = new Availability($this->cache);
    }

    public function testMarkDownSavesFlagForThirtySeconds(): void
    {
        $this->cache->expects(self::once())
            ->method('save')
            ->with('1', 'dmlab_typesense_is_down', ['DMLAB_TYPESENSE_INSTANT_SEARCH'], 30);

        $this->availability->markDown();
    }

    public function testIsDownWhenFlagCached(): void
    {
        $this->cache->expects(self::once())->method('load')->with('dmlab_typesense_is_down')->willReturn('1');

        self::assertTrue($this->availability->isDown());
    }

    public function testIsUpWhenFlagAbsent(): void
    {
        $this->cache->expects(self::once())->method('load')->with('dmlab_typesense_is_down')->willReturn(false);

        self::assertFalse($this->availability->isDown());
    }
}
