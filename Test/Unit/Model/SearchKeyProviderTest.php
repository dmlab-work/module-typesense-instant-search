<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model;

use DmLab\TypesenseInstantSearch\Model\Key\ScopedKeyDeriver;
use DmLab\TypesenseInstantSearch\Model\Key\StoreKeyRepository;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SearchKeyProviderTest extends TestCase
{
    /** @var StoreKeyRepository&MockObject */
    private $repository;

    private SearchKeyProvider $provider;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(StoreKeyRepository::class);
        $this->provider = new SearchKeyProvider($this->repository, new ScopedKeyDeriver());
    }

    public function testGetParentKeyReadsTheStoreAndRole(): void
    {
        $this->repository->expects(self::once())
            ->method('get')
            ->with(2, 'proxy')
            ->willReturn(['id' => 9, 'value' => 'parent']);

        self::assertSame('parent', $this->provider->getParentKey(2, 'proxy'));
    }

    public function testGetParentKeyIsNullWhenNotProvisioned(): void
    {
        $this->repository->method('get')->willReturn(null);

        self::assertNull($this->provider->getParentKey(1, 'public'));
    }

    public function testDeriveUsesTheStoreRoleParentAndNeverReturnsIt(): void
    {
        $this->repository->method('get')->with(1, 'public')->willReturn(['id' => 9, 'value' => 'the-parent-key']);

        $scoped = $this->provider->derive(1, 'public', ['per_page' => 5]);

        self::assertNotSame('the-parent-key', $scoped);
        self::assertSame((new ScopedKeyDeriver())->derive('the-parent-key', ['per_page' => 5]), $scoped);
    }

    public function testDeriveFailsClosedWithoutParentKey(): void
    {
        $this->repository->method('get')->willReturn(null);

        self::assertNull($this->provider->derive(1, 'proxy', []));
    }
}
