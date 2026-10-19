<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Plugin;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Plugin\RevokeSearchKeysOnDelete;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class RevokeSearchKeysOnDeleteTest extends TestCase
{
    /** @var SearchKeyProvisioner&MockObject */
    private $provisioner;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private RevokeSearchKeysOnDelete $plugin;

    protected function setUp(): void
    {
        $this->provisioner = $this->createMock(SearchKeyProvisioner::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new RevokeSearchKeysOnDelete($this->provisioner, $this->logger);
    }

    public function testStoreDeleteRevokesThatStoreAfterTheDelete(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(5);
        $subject = $this->createMock(AbstractDb::class);
        $log = [];
        $this->provisioner->method('keyIds')->willReturnCallback(function (int $id) use (&$log): array {
            $log[] = 'ids ' . $id;

            return [50, 51];
        });
        $this->provisioner->method('revokeIds')->willReturnCallback(function (array $ids) use (&$log): void {
            $log[] = 'revoke ' . implode(',', $ids);
        });

        $result = $this->plugin->aroundDelete($subject, function () use (&$log, $subject) {
            $log[] = 'delete';

            return $subject;
        }, $store);

        self::assertSame($subject, $result);
        self::assertSame(['ids 5', 'delete', 'revoke 50,51'], $log);
    }

    public function testFailedDeleteRevokesNothing(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(5);
        $this->provisioner->method('keyIds')->willReturn([50]);
        $this->provisioner->expects(self::never())->method('revokeIds');

        $this->expectException(\RuntimeException::class);
        $this->plugin->aroundDelete($this->createMock(AbstractDb::class), static function (): void {
            throw new \RuntimeException('Delete operation is forbidden for current area');
        }, $store);
    }

    public function testWebsiteDeleteRevokesEachOfItsStoresButNotAdmin(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn(['0', '3', '4']);
        $this->provisioner->method('keyIds')->willReturnCallback(static fn (int $id): array => [$id * 10]);
        $revoked = [];
        $this->provisioner->method('revokeIds')->willReturnCallback(function (array $ids) use (&$revoked): void {
            $revoked[] = $ids;
        });

        $this->plugin->aroundDelete($this->createMock(AbstractDb::class), static fn () => null, $website);

        self::assertSame([[30], [40]], $revoked);
    }

    public function testGroupDeleteRevokesEachOfItsStores(): void
    {
        $group = $this->createMock(Group::class);
        $group->method('getStoreIds')->willReturn(['6', '7']);
        $this->provisioner->method('keyIds')->willReturnCallback(static fn (int $id): array => [$id * 10]);
        $revoked = [];
        $this->provisioner->method('revokeIds')->willReturnCallback(function (array $ids) use (&$revoked): void {
            $revoked[] = $ids;
        });

        $this->plugin->aroundDelete($this->createMock(AbstractDb::class), static fn () => null, $group);

        self::assertSame([[60], [70]], $revoked);
    }

    public function testFailureIsLoggedNotRethrownAndOtherStoresStillRevoked(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn([3, 4]);
        $this->provisioner->method('keyIds')->willReturn([1]);
        $this->provisioner->expects(self::exactly(2))->method('revokeIds')
            ->willThrowException(new \RuntimeException('Typesense is down'));
        $this->logger->expects(self::exactly(2))->method('critical')
            ->with(self::stringContains('Typesense is down'));

        $this->plugin->aroundDelete($this->createMock(AbstractDb::class), static fn () => null, $website);
    }

    public function testOtherModelsAreIgnored(): void
    {
        $this->provisioner->expects(self::never())->method('keyIds');
        $this->provisioner->expects(self::never())->method('revokeIds');

        $this->plugin->aroundDelete(
            $this->createMock(AbstractDb::class),
            static fn () => null,
            $this->createMock(AbstractModel::class)
        );
    }
}
