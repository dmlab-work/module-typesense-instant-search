<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model;

use DmLab\TypesenseInstantSearch\Model\RateGuard;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RateGuardTest extends TestCase
{
    /** @var CacheInterface&MockObject */
    private $cache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testAllowsRequestsUpToTheLimitThenDenies(): void
    {
        $guard = new RateGuard($this->cache, 3, 10);

        // Counter climbs 0 → 1 → 2, each below the limit; the 4th load sees 3 and is denied.
        $this->cache->method('load')->willReturnOnConsecutiveCalls('0', '1', '2', '3');
        $this->cache->expects(self::exactly(3))->method('save');

        self::assertTrue($guard->allow('1.2.3.4'));
        self::assertTrue($guard->allow('1.2.3.4'));
        self::assertTrue($guard->allow('1.2.3.4'));
        self::assertFalse($guard->allow('1.2.3.4'));
    }

    public function testCountsWithinTheWindowSecondsUnderTheHashedKey(): void
    {
        $guard = new RateGuard($this->cache, 30, 10);
        $this->cache->method('load')->willReturn('4');

        $this->cache->expects(self::once())
            ->method('save')
            ->with('5', 'dmlab_typesense_is_rate_' . sha1('1.2.3.4'), self::anything(), 10);

        self::assertTrue($guard->allow('1.2.3.4'));
    }

    public function testDeniesExactlyAtTheBoundary(): void
    {
        $guard = new RateGuard($this->cache, 1, 10);
        $this->cache->method('load')->willReturn('1');
        $this->cache->expects(self::never())->method('save');

        self::assertFalse($guard->allow('1.2.3.4'));
    }

    public function testEmptyIdentifierIsAlwaysAllowedAndNeverTouchesTheCache(): void
    {
        $guard = new RateGuard($this->cache, 30, 10);
        $this->cache->expects(self::never())->method('load');
        $this->cache->expects(self::never())->method('save');

        self::assertTrue($guard->allow(''));
    }

    public function testGuardIsDisabledWhenMaxRequestsIsBelowOne(): void
    {
        $guard = new RateGuard($this->cache, 0, 10);
        $this->cache->expects(self::never())->method('load');
        $this->cache->expects(self::never())->method('save');

        self::assertTrue($guard->allow('1.2.3.4'));
    }
}
