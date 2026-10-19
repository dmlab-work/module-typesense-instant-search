<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\LegacyKeyMigrator;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyManager;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Model\Key\StoreKeyRepository;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SearchKeyProvisionerTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var SearchKeyManager&MockObject */
    private $keyManager;

    /** @var StoreKeyRepository&MockObject */
    private $keyRepository;

    /** @var LegacyKeyMigrator&MockObject */
    private $migrator;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var TypeListInterface&MockObject */
    private $cacheTypeList;

    /** @var array<string,array{id:int,value:string,collection:?string}> stored keys by "<storeId>:<role>" */
    private array $stored = [];

    /** @var string[] ordered side effects */
    private array $log = [];

    private int $nextId = 100;

    private SearchKeyProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->keyManager = $this->createMock(SearchKeyManager::class);
        $this->keyRepository = $this->createMock(StoreKeyRepository::class);
        $this->migrator = $this->createMock(LegacyKeyMigrator::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        $indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $indexNameResolver->method('getAliasName')->willReturnCallback(
            static fn (string $indexerId, int $storeId): string => $indexerId === Fulltext::INDEXER_ID
                ? 'alias_' . $storeId
                : 'unexpected'
        );
        $indexNameResolver->method('getPublicAliasName')->willReturnCallback(
            static fn (string $indexerId, int $storeId): string => $indexerId === Fulltext::INDEXER_ID
                ? 'pub_' . $storeId
                : 'unexpected'
        );

        $this->keyRepository->method('get')->willReturnCallback(
            fn (int $storeId, string $role): ?array => $this->stored[$storeId . ':' . $role] ?? null
        );
        $this->keyRepository->method('save')->willReturnCallback(
            function (int $storeId, string $role, int $id, string $value, string $collection): void {
                $this->stored[$storeId . ':' . $role] = ['id' => $id, 'value' => $value, 'collection' => $collection];
                $this->log[] = "save $storeId:$role:$id";
            }
        );
        $this->keyRepository->method('delete')->willReturnCallback(
            function (int $storeId, string $role): void {
                unset($this->stored[$storeId . ':' . $role]);
                $this->log[] = "row-delete $storeId:$role";
            }
        );
        $this->keyManager->method('createParentSearchKey')->willReturnCallback(
            function (array $collections, string $description): array {
                $id = $this->nextId++;
                $this->log[] = 'create ' . implode(',', $collections) . ' ' . $description;

                return ['id' => $id, 'value' => 'value-' . $id];
            }
        );
        $this->keyManager->method('deleteKey')->willReturnCallback(function (int $id): void {
            $this->log[] = "delete $id";
        });
        $this->cacheTypeList->method('cleanType')->willReturnCallback(function (string $type): void {
            $this->log[] = "clean $type";
        });
        $this->migrator->method('findKeyIds')->willReturnCallback(function (): ?array {
            $this->log[] = 'migrate';

            return null;
        });

        $this->provisioner = new SearchKeyProvisioner(
            $this->config,
            $this->keyManager,
            $this->keyRepository,
            $this->migrator,
            $indexNameResolver,
            $this->storeManager,
            $this->cacheTypeList
        );
    }

    public function testEnsureCreatesPublicAndProxyKeysPerStoreInDirectMode(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->givenStores([1 => 'default', 2 => 'de']);

        $results = $this->provisioner->ensure();

        self::assertSame([
            'migrate',
            'create pub_1 dmlab-instant-search:public:pub_1',
            'save 1:public:100',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:101',
            'create pub_2 dmlab-instant-search:public:pub_2',
            'save 2:public:102',
            'create alias_2 dmlab-instant-search:proxy:alias_2',
            'save 2:proxy:103',
            'clean full_page',
        ], $this->log);
        self::assertSame(['store' => 'default', 'role' => 'public', 'status' => 'created'], $results[0]);
        self::assertCount(4, $results);
    }

    public function testEnsureProvisionsOnlyTheProxyKeyInProxyMode(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);

        $results = $this->provisioner->ensure();

        self::assertSame([
            'migrate',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:100',
            'clean full_page',
        ], $this->log);
        self::assertSame([['store' => 'default', 'role' => 'proxy', 'status' => 'created']], $results);
    }

    public function testEnsureSkipsExistingKeysAndDoesNotCleanWhenNothingChanged(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->givenStores([1 => 'default']);
        $this->stored = [
            '1:public' => ['id' => 5, 'value' => 'p', 'collection' => 'pub_1'],
            '1:proxy' => ['id' => 6, 'value' => 'x', 'collection' => 'alias_1'],
        ];

        $results = $this->provisioner->ensure();

        self::assertSame(['migrate'], $this->log);
        self::assertSame(['exists', 'exists'], array_column($results, 'status'));
    }

    public function testEnsureInProxyModeRevokesAStalePublicKeyAfterCleaningPages(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = [
            '1:public' => ['id' => 5, 'value' => 'p', 'collection' => 'pub_1'],
            '1:proxy' => ['id' => 6, 'value' => 'x', 'collection' => 'alias_1'],
        ];

        $results = $this->provisioner->ensure();

        self::assertSame(['migrate', 'row-delete 1:public', 'clean full_page', 'delete 5'], $this->log);
        self::assertSame(['revoked', 'exists'], array_column($results, 'status'));
    }

    public function testEnsureRotatesAKeyScopedToAnotherCollection(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'x', 'collection' => 'old_prefix_1']];

        $results = $this->provisioner->ensure();

        self::assertSame([
            'migrate',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:100',
            'clean full_page',
            'delete 6',
        ], $this->log);
        self::assertSame([['store' => 'default', 'role' => 'proxy', 'status' => 'rotated']], $results);
    }

    public function testEnsureRotatesAKeySavedWithoutACollection(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'x', 'collection' => null]];

        $results = $this->provisioner->ensure();

        self::assertSame(['rotated'], array_column($results, 'status'));
        self::assertSame(['id' => 100, 'value' => 'value-100', 'collection' => 'alias_1'], $this->stored['1:proxy']);
    }

    public function testEnsureForOneStoreOnlyTouchesThatStore(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $store = $this->store(2, 'de');
        $this->storeManager->expects(self::never())->method('getStores');
        $this->storeManager->method('getStore')->with(2)->willReturn($store);

        $this->provisioner->ensure(2);

        self::assertSame([
            'migrate',
            'create alias_2 dmlab-instant-search:proxy:alias_2',
            'save 2:proxy:100',
            'clean full_page',
        ], $this->log);
    }

    public function testEnsureCleansFullPageWhenOnlyTheLegacyKeyWasMigrated(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'x', 'collection' => 'alias_1']];

        $this->provisionerWithLegacy([])->ensure();

        self::assertSame(['clean full_page', 'revoke-legacy'], $this->log);
    }

    public function testEnsureRevokesTheLegacyKeyOnlyAfterNewKeysAndFullPageClean(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->givenStores([1 => 'default']);

        $this->provisionerWithLegacy([42])->ensure();

        self::assertSame([
            'create pub_1 dmlab-instant-search:public:pub_1',
            'save 1:public:100',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:101',
            'clean full_page',
            'revoke-legacy 42',
        ], $this->log);
    }

    public function testRegenerateRevokesTheLegacyKeyOnlyAfterNewKeysAndFullPageClean(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);

        $this->provisionerWithLegacy([42])->regenerate();

        self::assertSame([
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:100',
            'clean full_page',
            'revoke-legacy 42',
        ], $this->log);
    }

    public function testFailedLegacyRevokeStillDeletesOldIdsAndPropagates(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'x', 'collection' => 'alias_1']];
        $migrator = $this->createMock(LegacyKeyMigrator::class);
        $migrator->method('findKeyIds')->willReturn([42]);
        $migrator->method('revoke')->willThrowException(new TypesenseException('legacy down', 503));

        try {
            $this->provisionerWith($this->keyManager, $migrator)->regenerate();
            self::fail('Expected the legacy revoke failure to propagate.');
        } catch (TypesenseException $e) {
            self::assertSame('legacy down', $e->getMessage());
        }

        self::assertSame([
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:100',
            'clean full_page',
            'delete 6',
        ], $this->log);
    }

    public function testRegenerateCreatesSavesCleansThenDeletesOldIds(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:public' => ['id' => 5, 'value' => 'p'], '1:proxy' => ['id' => 6, 'value' => 'x']];

        $results = $this->provisioner->regenerate();

        self::assertSame([
            'migrate',
            'create pub_1 dmlab-instant-search:public:pub_1',
            'save 1:public:100',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:101',
            'clean full_page',
            'delete 5',
            'delete 6',
        ], $this->log);
        self::assertSame(['rotated', 'rotated'], array_column($results, 'status'));
    }

    public function testRegenerateWithoutAnOldKeyCreatesAndDeletesNothing(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);

        $results = $this->provisioner->regenerate();

        self::assertSame([
            'migrate',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:100',
            'clean full_page',
        ], $this->log);
        self::assertSame('created', $results[0]['status']);
    }

    public function testRegenerateFailedCreateKeepsTheOldKey(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'old']];
        $keyManager = $this->createMock(SearchKeyManager::class);
        $keyManager->method('createParentSearchKey')->willThrowException(new TypesenseException('boom', 500));
        $keyManager->expects(self::never())->method('deleteKey');
        $this->cacheTypeList->expects(self::never())->method('cleanType');
        $provisioner = $this->provisionerWith($keyManager);

        try {
            $provisioner->regenerate();
            self::fail('Expected the create failure to propagate.');
        } catch (TypesenseException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['id' => 6, 'value' => 'old'], $this->stored['1:proxy']);
    }

    public function testRegenerateFinalisesKeysRotatedBeforeAFailure(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default', 2 => 'de']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'old1'], '2:proxy' => ['id' => 7, 'value' => 'old2']];
        $keyManager = $this->createMock(SearchKeyManager::class);
        $keyManager->method('createParentSearchKey')->willReturnOnConsecutiveCalls(
            ['id' => 100, 'value' => 'new1'],
            self::throwException(new TypesenseException('boom', 500))
        );
        $keyManager->expects(self::once())->method('deleteKey')->with(6);
        $provisioner = $this->provisionerWith($keyManager);

        try {
            $provisioner->regenerate();
            self::fail('Expected the create failure to propagate.');
        } catch (TypesenseException) {
        }

        self::assertContains('clean full_page', $this->log);
        self::assertSame(['id' => 7, 'value' => 'old2'], $this->stored['2:proxy']);
    }

    public function testRegenerateAttemptsEveryOldIdAndNamesTheFailedOnes(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:public' => ['id' => 5, 'value' => 'p'], '1:proxy' => ['id' => 6, 'value' => 'x']];
        $keyManager = $this->createMock(SearchKeyManager::class);
        $keyManager->method('createParentSearchKey')->willReturn(['id' => 100, 'value' => 'new']);
        $deleted = [];
        $keyManager->method('deleteKey')->willReturnCallback(function (int $id) use (&$deleted): void {
            $deleted[] = $id;
            if ($id === 5) {
                throw new TypesenseException('boom', 500);
            }
        });

        try {
            $this->provisionerWith($keyManager)->regenerate();
            self::fail('Expected the delete failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('5', $e->getMessage());
            self::assertStringNotContainsString('6', $e->getMessage());
            self::assertInstanceOf(TypesenseException::class, $e->getPrevious());
        }

        self::assertSame([5, 6], $deleted);
    }

    public function testRegenerateKeepsTheCreateFailureWhenOldIdsAreDeleted(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default', 2 => 'de']);
        $this->stored = ['1:proxy' => ['id' => 6, 'value' => 'old1']];
        $keyManager = $this->createMock(SearchKeyManager::class);
        $keyManager->method('createParentSearchKey')->willReturnOnConsecutiveCalls(
            ['id' => 100, 'value' => 'new1'],
            self::throwException(new TypesenseException('create failed', 500))
        );

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('create failed');

        $this->provisionerWith($keyManager)->regenerate();
    }

    public function testRegenerateInProxyModeRevokesAStalePublicKey(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default']);
        $this->stored = ['1:public' => ['id' => 5, 'value' => 'p'], '1:proxy' => ['id' => 6, 'value' => 'x']];

        $results = $this->provisioner->regenerate();

        self::assertSame([
            'migrate',
            'row-delete 1:public',
            'create alias_1 dmlab-instant-search:proxy:alias_1',
            'save 1:proxy:100',
            'clean full_page',
            'delete 5',
            'delete 6',
        ], $this->log);
        self::assertSame(['revoked', 'rotated'], array_column($results, 'status'));
    }

    public function testAdminStoreIsNeverProvisioned(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->storeManager->expects(self::never())->method('getStore');

        self::assertSame([], $this->provisioner->ensure(0));
        self::assertSame(['migrate'], $this->log);
    }

    public function testEnsureFailureStillCleansFullPageForKeysAlreadyCreated(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->givenStores([1 => 'default', 2 => 'de']);
        $keyManager = $this->createMock(SearchKeyManager::class);
        $keyManager->method('createParentSearchKey')->willReturnOnConsecutiveCalls(
            ['id' => 100, 'value' => 'new1'],
            self::throwException(new TypesenseException('boom', 500))
        );

        try {
            $this->provisionerWith($keyManager)->ensure();
            self::fail('Expected the create failure to propagate.');
        } catch (TypesenseException) {
        }

        self::assertSame(['save 1:proxy:100', 'clean full_page'], $this->log);
    }

    public function testKeyIdsReturnsStoredIdsOfEveryRole(): void
    {
        $this->stored = ['3:public' => ['id' => 5, 'value' => 'p'], '3:proxy' => ['id' => 6, 'value' => 'x']];

        self::assertSame([5, 6], $this->provisioner->keyIds(3));
        self::assertSame([], $this->provisioner->keyIds(4));
        self::assertSame([], $this->log);
    }

    public function testRevokeIdsDeletesOnlyOnTypesense(): void
    {
        $this->provisioner->revokeIds([5, 6]);

        self::assertSame(['delete 5', 'delete 6'], $this->log);
    }

    public function testRevokeIdsTriesEveryIdAndNamesTheFailedOnes(): void
    {
        $keyManager = $this->createMock(SearchKeyManager::class);
        $deleted = [];
        $keyManager->method('deleteKey')->willReturnCallback(function (int $id) use (&$deleted): void {
            $deleted[] = $id;
            if ($id === 5) {
                throw new TypesenseException('boom', 500);
            }
        });

        try {
            $this->provisionerWith($keyManager)->revokeIds([5, 6]);
            self::fail('Expected the delete failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('5', $e->getMessage());
            self::assertStringNotContainsString('6', $e->getMessage());
        }

        self::assertSame([5, 6], $deleted);
    }

    private function provisionerWith(
        SearchKeyManager $keyManager,
        ?LegacyKeyMigrator $migrator = null
    ): SearchKeyProvisioner {
        $indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $indexNameResolver->method('getAliasName')->willReturnCallback(
            static fn (string $indexerId, int $storeId): string => 'alias_' . $storeId
        );
        $indexNameResolver->method('getPublicAliasName')->willReturnCallback(
            static fn (string $indexerId, int $storeId): string => 'pub_' . $storeId
        );

        return new SearchKeyProvisioner(
            $this->config,
            $keyManager,
            $this->keyRepository,
            $migrator ?? $this->createMock(LegacyKeyMigrator::class),
            $indexNameResolver,
            $this->storeManager,
            $this->cacheTypeList
        );
    }

    /**
     * @param int[] $legacyIds
     */
    private function provisionerWithLegacy(array $legacyIds): SearchKeyProvisioner
    {
        $migrator = $this->createMock(LegacyKeyMigrator::class);
        $migrator->method('findKeyIds')->willReturn($legacyIds);
        $migrator->method('revoke')->willReturnCallback(function (array $ids): void {
            $this->log[] = trim('revoke-legacy ' . implode(',', $ids));
        });

        return $this->provisionerWith($this->keyManager, $migrator);
    }

    /**
     * @param array<int,string> $stores id => code
     */
    private function givenStores(array $stores): void
    {
        $list = [];
        foreach ($stores as $id => $code) {
            $list[$id] = $this->store($id, $code);
        }
        $this->storeManager->method('getStores')->willReturn($list);
    }

    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);

        return $store;
    }
}
