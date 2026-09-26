<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyManager;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
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

    /** @var SearchKeyProvider&MockObject */
    private $keyProvider;

    /** @var SearchKeyManager&MockObject */
    private $keyManager;

    /** @var IndexNameResolverInterface&MockObject */
    private $indexNameResolver;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var WriterInterface&MockObject */
    private $configWriter;

    /** @var EncryptorInterface&MockObject */
    private $encryptor;

    /** @var TypeListInterface&MockObject */
    private $cacheTypeList;

    private SearchKeyProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->keyProvider = $this->createMock(SearchKeyProvider::class);
        $this->keyManager = $this->createMock(SearchKeyManager::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        $this->provisioner = new SearchKeyProvisioner(
            $this->config,
            $this->keyProvider,
            $this->keyManager,
            $this->indexNameResolver,
            $this->storeManager,
            $this->configWriter,
            $this->encryptor,
            $this->cacheTypeList
        );
    }

    public function testEnsureProvisionsInDirectModeWhenNoKeyExists(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->keyProvider->method('hasParentKey')->willReturn(false);
        $this->givenStores([1]);
        $this->indexNameResolver->method('getAliasName')
            ->with(Fulltext::INDEXER_ID, 1)
            ->willReturn('typesense_catalogsearch_fulltext_1');

        $this->keyManager->expects(self::once())
            ->method('createParentSearchKey')
            ->with(['typesense_catalogsearch_fulltext_1'])
            ->willReturn('parent-key-value');
        $this->encryptor->method('encrypt')->with('parent-key-value')->willReturn('enc:parent-key-value');
        $this->configWriter->expects(self::once())
            ->method('save')
            ->with(SearchKeyProvider::XML_PATH_SEARCH_API_KEY, 'enc:parent-key-value');
        $this->cacheTypeList->expects(self::once())
            ->method('cleanType')
            ->with(ConfigCache::TYPE_IDENTIFIER);

        $this->provisioner->ensure();
    }

    public function testEnsureIsIdempotentWhenKeyAlreadyExists(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->keyProvider->method('hasParentKey')->willReturn(true);

        $this->keyManager->expects(self::never())->method('createParentSearchKey');
        $this->configWriter->expects(self::never())->method('save');

        $this->provisioner->ensure();
    }

    public function testEnsureDoesNothingInProxyModeEvenWithoutAKey(): void
    {
        $this->config->method('isDirectMode')->willReturn(false);
        $this->keyProvider->method('hasParentKey')->willReturn(false);

        $this->keyManager->expects(self::never())->method('createParentSearchKey');
        $this->configWriter->expects(self::never())->method('save');

        $this->provisioner->ensure();
    }

    public function testEnsureScopesTheKeyToEveryStoreAliasWithoutDuplicates(): void
    {
        $this->config->method('isDirectMode')->willReturn(true);
        $this->keyProvider->method('hasParentKey')->willReturn(false);
        $this->givenStores([1, 2, 3]);
        $this->indexNameResolver->method('getAliasName')->willReturnMap([
            [Fulltext::INDEXER_ID, 1, 'alias_1'],
            [Fulltext::INDEXER_ID, 2, 'alias_1'], // same alias — must be de-duplicated
            [Fulltext::INDEXER_ID, 3, 'alias_3'],
        ]);

        $this->keyManager->expects(self::once())
            ->method('createParentSearchKey')
            ->with(['alias_1', 'alias_3'])
            ->willReturn('v');
        $this->encryptor->method('encrypt')->willReturn('enc');

        $this->provisioner->ensure();
    }

    public function testRegenerateProvisionsEvenWhenAKeyAlreadyExists(): void
    {
        // Regenerate is unconditional — it overwrites the stored key regardless of hasParentKey().
        $this->keyProvider->expects(self::never())->method('hasParentKey');
        $this->givenStores([1]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias_1');

        // The old key is retired on the Typesense side before a fresh one is minted.
        $this->keyManager->expects(self::once())->method('deleteExistingSearchKeys');
        $this->keyManager->expects(self::once())
            ->method('createParentSearchKey')
            ->with(['alias_1'])
            ->willReturn('fresh-key');
        $this->encryptor->method('encrypt')->with('fresh-key')->willReturn('enc:fresh-key');
        $this->configWriter->expects(self::once())
            ->method('save')
            ->with(SearchKeyProvider::XML_PATH_SEARCH_API_KEY, 'enc:fresh-key');

        $this->provisioner->regenerate();
    }

    /**
     * @param int[] $storeIds
     */
    private function givenStores(array $storeIds): void
    {
        $stores = [];
        foreach ($storeIds as $id) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[] = $store;
        }
        $this->storeManager->method('getStores')->willReturn($stores);
    }
}
