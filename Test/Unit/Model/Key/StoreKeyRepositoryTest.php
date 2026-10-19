<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseInstantSearch\Model\Key\StoreKeyRepository;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class StoreKeyRepositoryTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var WriterInterface&MockObject */
    private $configWriter;

    /** @var EncryptorInterface&MockObject */
    private $encryptor;

    /** @var TypeListInterface&MockObject */
    private $cacheTypeList;

    private StoreKeyRepository $repository;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->repository = new StoreKeyRepository(
            $this->scopeConfig,
            $this->configWriter,
            $this->encryptor,
            $this->cacheTypeList
        );
    }

    public function testGetReadsStoreScopeAndDecrypts(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['dmlab_typesense/instant_search/proxy_key', ScopeInterface::SCOPE_STORE, 2, 'enc'],
            ['dmlab_typesense/instant_search/proxy_key_id', ScopeInterface::SCOPE_STORE, 2, '42'],
            ['dmlab_typesense/instant_search/proxy_key_collection', ScopeInterface::SCOPE_STORE, 2, 'alias_2'],
        ]);
        $this->encryptor->method('decrypt')->with('enc')->willReturn(' plain ');

        self::assertSame(
            ['id' => 42, 'value' => 'plain', 'collection' => 'alias_2'],
            $this->repository->get(2, 'proxy')
        );
    }

    public function testGetReportsANullCollectionForKeysSavedWithoutOne(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['dmlab_typesense/instant_search/proxy_key', ScopeInterface::SCOPE_STORE, 2, 'enc'],
            ['dmlab_typesense/instant_search/proxy_key_id', ScopeInterface::SCOPE_STORE, 2, '42'],
            ['dmlab_typesense/instant_search/proxy_key_collection', ScopeInterface::SCOPE_STORE, 2, null],
        ]);
        $this->encryptor->method('decrypt')->willReturn('plain');

        self::assertNull($this->repository->get(2, 'proxy')['collection']);
    }

    public function testGetIsNullWhenValueOrIdMissing(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['dmlab_typesense/instant_search/public_key', ScopeInterface::SCOPE_STORE, 1, 'enc'],
            ['dmlab_typesense/instant_search/public_key_id', ScopeInterface::SCOPE_STORE, 1, null],
            ['dmlab_typesense/instant_search/proxy_key', ScopeInterface::SCOPE_STORE, 1, null],
            ['dmlab_typesense/instant_search/proxy_key_id', ScopeInterface::SCOPE_STORE, 1, '5'],
        ]);
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertNull($this->repository->get(1, 'public'));
        self::assertNull($this->repository->get(1, 'proxy'));
    }

    public function testGetIsNullWhenDecryptedValueIsEmpty(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['dmlab_typesense/instant_search/proxy_key', ScopeInterface::SCOPE_STORE, 1, 'enc'],
            ['dmlab_typesense/instant_search/proxy_key_id', ScopeInterface::SCOPE_STORE, 1, '5'],
        ]);
        $this->encryptor->method('decrypt')->willReturn('');

        self::assertNull($this->repository->get(1, 'proxy'));
    }

    public function testSaveWritesEncryptedValueIdAndCollectionAtStoreScopeAndCleansConfigCache(): void
    {
        $this->encryptor->method('encrypt')->with('plain')->willReturn('enc');
        $saved = [];
        $this->configWriter->expects(self::exactly(3))
            ->method('save')
            ->willReturnCallback(static function (...$args) use (&$saved): void {
                $saved[] = $args;
            });
        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('config');

        $this->repository->save(3, 'public', 12, 'plain', 'alias_3_public');

        self::assertSame([
            ['dmlab_typesense/instant_search/public_key', 'enc', ScopeInterface::SCOPE_STORES, 3],
            ['dmlab_typesense/instant_search/public_key_id', '12', ScopeInterface::SCOPE_STORES, 3],
            ['dmlab_typesense/instant_search/public_key_collection', 'alias_3_public', ScopeInterface::SCOPE_STORES, 3],
        ], $saved);
    }

    public function testDeleteRemovesEveryRowAtStoreScope(): void
    {
        $deleted = [];
        $this->configWriter->expects(self::exactly(3))
            ->method('delete')
            ->willReturnCallback(static function (...$args) use (&$deleted): void {
                $deleted[] = $args;
            });
        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('config');

        $this->repository->delete(3, 'proxy');

        self::assertSame([
            ['dmlab_typesense/instant_search/proxy_key', ScopeInterface::SCOPE_STORES, 3],
            ['dmlab_typesense/instant_search/proxy_key_id', ScopeInterface::SCOPE_STORES, 3],
            ['dmlab_typesense/instant_search/proxy_key_collection', ScopeInterface::SCOPE_STORES, 3],
        ], $deleted);
    }

    public function testGetReturnsKeysWrittenInThisProcessDespiteStaleScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->encryptor->method('encrypt')->willReturn('enc');

        $this->repository->save(2, 'proxy', 42, 'plain', 'alias_2');
        self::assertSame(
            ['id' => 42, 'value' => 'plain', 'collection' => 'alias_2'],
            $this->repository->get(2, 'proxy')
        );

        $this->repository->delete(2, 'proxy');
        self::assertNull($this->repository->get(2, 'proxy'));
    }
}
