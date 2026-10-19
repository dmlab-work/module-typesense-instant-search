<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Observer;

use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Observer\EnsureStoreSearchKeys;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class EnsureStoreSearchKeysTest extends TestCase
{
    /** @var SearchKeyProvisioner&MockObject */
    private $provisioner;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var int[] stores with instant search enabled */
    private array $enabled = [1, 2, 3, 4];

    /** @var ReinitableConfigInterface&MockObject */
    private $reinitableConfig;

    private EnsureStoreSearchKeys $observer;

    protected function setUp(): void
    {
        $this->provisioner = $this->createMock(SearchKeyProvisioner::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn (?int $id): bool => in_array($id, $this->enabled, true));
        $this->reinitableConfig = $this->createMock(ReinitableConfigInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturnCallback(
            fn (): array => array_map(fn (int $id): StoreInterface => $this->store($id), [1, 2, 3, 4])
        );
        $this->observer = new EnsureStoreSearchKeys(
            $this->provisioner,
            $this->logger,
            $config,
            $this->reinitableConfig,
            $storeManager
        );
    }

    public function testStoresWithInstantSearchDisabledAreStillEnsured(): void
    {
        $this->enabled = [4];
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn([1, 2]);
        $calls = [];
        $this->provisioner->expects(self::exactly(2))->method('ensure')
            ->willReturnCallback(static function (?int $id) use (&$calls): array {
                $calls[] = $id;

                return [];
            });

        $this->observer->execute($this->observerWith(['website' => $website]));

        self::assertSame([1, 2], $calls);
    }

    public function testNothingIsEnsuredWhileInstantSearchIsDisabledEverywhere(): void
    {
        $this->enabled = [];
        $this->provisioner->expects(self::never())->method('ensure');

        $this->observer->execute($this->observerWith(['store' => $this->store(3)]));
    }

    public function testStoreAddOrEditEnsuresThatStore(): void
    {
        $this->provisioner->expects(self::once())->method('ensure')->with(3);

        $this->observer->execute($this->observerWith(['store' => $this->store(3)]));
    }

    public function testStoreAddReloadsConfigBeforeReadingIt(): void
    {
        $this->enabled = [];
        $this->reinitableConfig->expects(self::once())->method('reinit')
            ->willReturnCallback(function (): ReinitableConfigInterface {
                $this->enabled = [1];

                return $this->reinitableConfig;
            });
        $this->provisioner->expects(self::once())->method('ensure')->with(5);

        $this->observer->execute($this->observerWith(['store' => $this->store(5)], 'store_add'));
    }

    public function testStoreEditDoesNotReloadConfig(): void
    {
        $this->reinitableConfig->expects(self::never())->method('reinit');

        $this->observer->execute($this->observerWith(['store' => $this->store(3)], 'store_edit'));
    }

    public function testWebsiteSaveEnsuresEachOfItsStores(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn(['1' => '1', '4' => '4']);
        $calls = [];
        $this->provisioner->expects(self::exactly(2))->method('ensure')
            ->willReturnCallback(static function (?int $id) use (&$calls): array {
                $calls[] = $id;

                return [];
            });

        $this->observer->execute($this->observerWith(['website' => $website]));

        self::assertSame([1, 4], $calls);
    }

    public function testAdminStoreIsSkipped(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn([0 => '0']);
        $this->provisioner->expects(self::never())->method('ensure');

        $this->observer->execute($this->observerWith(['website' => $website]));
    }

    public function testFailureIsLoggedAndOtherStoresStillRun(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn([1, 2]);
        $this->provisioner->expects(self::exactly(2))->method('ensure')
            ->willReturnCallback(static function (?int $id): array {
                if ($id === 1) {
                    throw new \RuntimeException('Typesense is down');
                }

                return [];
            });
        $this->logger->expects(self::once())->method('critical')
            ->with(self::stringContains('Typesense is down'));

        $this->observer->execute($this->observerWith(['website' => $website]));
    }

    public function testEventWithoutStoreOrWebsiteIsIgnored(): void
    {
        $this->provisioner->expects(self::never())->method('ensure');

        $this->observer->execute($this->observerWith([]));
    }

    private function store(int $id): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);

        return $store;
    }

    private function observerWith(array $data, string $eventName = ''): Observer
    {
        $event = new Event($data);
        $event->setName($eventName);

        return new Observer(['event' => $event]);
    }
}
