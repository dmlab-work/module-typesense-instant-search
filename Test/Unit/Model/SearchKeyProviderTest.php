<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model;

use DmLab\TypesenseInstantSearch\Model\Key\ScopedKeyDeriver;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SearchKeyProviderTest extends TestCase
{
    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var EncryptorInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $encryptor;

    private SearchKeyProvider $provider;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        // A real deriver: the provider must produce a derived key, not the parent, without any HTTP.
        $this->provider = new SearchKeyProvider($this->scopeConfig, $this->encryptor, new ScopedKeyDeriver());
    }

    public function testReadsItsOwnPathNotCoresAdminKeyPath(): void
    {
        // The request path can only ever see the search-only key path — never the admin key under
        // catalog/search. The constant makes that boundary structural.
        self::assertSame(
            'dmlab_typesense/instant_search/search_api_key',
            SearchKeyProvider::XML_PATH_SEARCH_API_KEY
        );
        self::assertStringNotContainsString('catalog/search', SearchKeyProvider::XML_PATH_SEARCH_API_KEY);
    }

    public function testHasParentKeyIsTrueWhenConfigured(): void
    {
        $this->givenStoredParentKey('parent');

        self::assertTrue($this->provider->hasParentKey());
    }

    public function testHasParentKeyIsFalseWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SearchKeyProvider::XML_PATH_SEARCH_API_KEY)
            ->willReturn(null);

        self::assertFalse($this->provider->hasParentKey());
    }

    public function testGetScopedSearchKeyDerivesFromTheParentAndNeverReturnsIt(): void
    {
        $this->givenStoredParentKey('the-parent-key');

        $scoped = $this->provider->getScopedSearchKey(['filter_by' => 'store_id:1']);

        self::assertIsString($scoped);
        self::assertNotSame('the-parent-key', $scoped);
        self::assertSame(
            (new ScopedKeyDeriver())->derive('the-parent-key', ['filter_by' => 'store_id:1']),
            $scoped
        );
    }

    public function testGetScopedSearchKeyFailsClosedWhenNoParentKey(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SearchKeyProvider::XML_PATH_SEARCH_API_KEY)
            ->willReturn('');

        self::assertNull($this->provider->getScopedSearchKey());
    }

    /**
     * @param string $decrypted
     */
    private function givenStoredParentKey(string $decrypted): void
    {
        $this->scopeConfig->method('getValue')
            ->with(SearchKeyProvider::XML_PATH_SEARCH_API_KEY)
            ->willReturn('encrypted-blob');
        $this->encryptor->method('decrypt')->with('encrypted-blob')->willReturn($decrypted);
    }
}
