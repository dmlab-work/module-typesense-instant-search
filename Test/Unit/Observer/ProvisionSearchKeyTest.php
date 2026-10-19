<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Observer;

use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Observer\ProvisionSearchKey;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ProvisionSearchKeyTest extends TestCase
{
    /** @var SearchKeyProvisioner&MockObject */
    private $provisioner;

    /** @var int[] stores with instant search enabled */
    private array $enabled = [2];

    private ProvisionSearchKey $observer;

    protected function setUp(): void
    {
        $this->provisioner = $this->createMock(SearchKeyProvisioner::class);
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn (?int $id): bool => in_array($id, $this->enabled, true));
        $stores = [];
        foreach ([1, 2] as $id) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[$id] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $this->observer = new ProvisionSearchKey($this->provisioner, $config, $storeManager);
    }

    public function testSkippedWhenInstantSearchIsDisabledEverywhere(): void
    {
        $this->enabled = [];
        $this->provisioner->expects(self::never())->method('ensure');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testDelegatesToTheProvisionerOnSave(): void
    {
        $this->provisioner->expects(self::once())->method('ensure');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testWrapsProvisionerFailureAsASaveTimeError(): void
    {
        $this->provisioner->method('ensure')
            ->willThrowException(new \RuntimeException('No Typesense admin API key configured.'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No Typesense admin API key configured.');

        $this->observer->execute($this->createMock(Observer::class));
    }
}
