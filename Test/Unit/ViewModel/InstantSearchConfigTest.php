<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\ViewModel;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Config\Source\Mode;
use DmLab\TypesenseInstantSearch\Model\Price\GuestPriceDisplayProvider;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use DmLab\TypesenseInstantSearch\ViewModel\InstantSearchConfig;
use Magento\Catalog\Model\Product\Image\UrlBuilder as ImageUrlBuilder;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InstantSearchConfigTest extends TestCase
{
    private const ENDPOINT = 'https://magento.loc/typesense/ajax/search';

    private const IMAGE_TEMPLATE = 'https://magento.loc/media/catalog/product/cache/abc123/__DMLAB_IMAGE__';

    /** @var Config&MockObject */
    private $config;

    /** @var SearchKeyProvider&MockObject */
    private $searchKeyProvider;

    /** @var SearchableFieldsProviderInterface&MockObject */
    private $searchableFields;

    /** @var IndexNameResolverInterface&MockObject */
    private $indexNameResolver;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var MediaConfig&MockObject */
    private $mediaConfig;

    /** @var Visibility&MockObject */
    private $productVisibility;

    /** @var UrlInterface&MockObject */
    private $urlBuilder;

    /** @var FormatInterface&MockObject */
    private $localeFormat;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var DateTime&MockObject */
    private $dateTime;

    /** @var GuestPriceDisplayProvider&MockObject */
    private $guestPriceDisplay;

    /** @var ImageUrlBuilder&MockObject */
    private $imageUrlBuilder;

    private int|string|null $customerGroup = null;

    private int $now = 1_000_000;

    private int|string|null $fpcTtl = 3600;

    private InstantSearchConfig $viewModel;

    /** The current store's proxy parent key. */
    private ?string $proxyParentKey = 'proxy-parent';

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->searchKeyProvider = $this->createMock(SearchKeyProvider::class);
        $this->searchKeyProvider->method('getParentKey')->willReturnCallback(
            fn (int $storeId, string $role): ?string => $role === 'proxy' ? $this->proxyParentKey : null
        );
        $this->searchableFields = $this->createMock(SearchableFieldsProviderInterface::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->indexNameResolver->method('getPublicAliasName')->willReturn('typesense_catalogsearch_fulltext_1_public');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->mediaConfig->method('getBaseMediaUrl')->willReturn('https://magento.loc/media/catalog/product');
        $this->productVisibility = $this->createMock(Visibility::class);
        $this->productVisibility->method('getVisibleInSearchIds')->willReturn([3, 4]);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getBaseUrl')->willReturn('https://magento.loc/');
        $this->storeManager->method('getStore')->willReturn($store);

        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->method('getValue')->with(CustomerContext::CONTEXT_GROUP)
            ->willReturnCallback(fn () => $this->customerGroup);

        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->urlBuilder->method('getUrl')->with('typesense/ajax/search')->willReturn(self::ENDPOINT);

        $this->localeFormat = $this->createMock(FormatInterface::class);
        $this->localeFormat->method('getPriceFormat')->willReturn(['pattern' => '$%s', 'precision' => 2]);

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => match ($path) {
                'catalog/seo/product_url_suffix' => '.html',
                'system/full_page_cache/ttl' => $this->fpcTtl,
                default => null,
            }
        );

        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $this->guestPriceDisplay = $this->createMock(GuestPriceDisplayProvider::class);
        $this->guestPriceDisplay->method('get')->with($store)
            ->willReturn(['rate' => 0.92, 'taxFactors' => [0 => 1.0, 2 => 1.0825]]);

        $this->imageUrlBuilder = $this->createMock(ImageUrlBuilder::class);
        $this->imageUrlBuilder->method('getUrl')->with('/__DMLAB_IMAGE__', 'product_thumbnail_image')
            ->willReturn(self::IMAGE_TEMPLATE);

        $this->viewModel = new InstantSearchConfig(
            $this->config,
            $this->searchKeyProvider,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->storeManager,
            $this->mediaConfig,
            $this->productVisibility,
            $httpContext,
            $this->urlBuilder,
            $this->localeFormat,
            $this->scopeConfig,
            $this->dateTime,
            $this->guestPriceDisplay,
            $this->imageUrlBuilder
        );
    }

    public function testGuestDirectModeQueriesThePublicAliasWithThePublicKey(): void
    {
        $this->givenDirect(['name' => 5]);
        $this->searchKeyProvider->method('derive')->willReturn('derived-scoped-key');

        $config = $this->viewModel->getConfig();

        self::assertSame('direct', $config['mode']);
        self::assertSame('search.typesense.example', $config['host']);
        self::assertSame('derived-scoped-key', $config['apiKey']);
        self::assertSame('typesense_catalogsearch_fulltext_1_public', $config['collection']);
        self::assertSame('price', $config['priceField']);
        self::assertSame(['pattern' => '$%s', 'precision' => 2], $config['priceFormat']);
        self::assertSame(0.92, $config['currencyRate']);
        self::assertSame([0 => 1.0, 2 => 1.0825], $config['taxFactors']);
        self::assertSame('https://magento.loc/media/catalog/product', $config['mediaUrl']);
        self::assertSame(self::IMAGE_TEMPLATE, $config['imageUrlTemplate']);
        self::assertSame(['title' => true, 'sku' => false], $config['displayFields']);
        self::assertSame(self::ENDPOINT, $config['endpoint']);
        self::assertSame(7, $config['minQueryLength']);
        self::assertSame(9, $config['resultLimit']);
        self::assertSame(150, $config['debounceMs']);
        self::assertSame('https://magento.loc/', $config['baseUrl']);
        self::assertSame('.html', $config['productUrlSuffix']);
    }

    public function testResolvesThePublicAliasForTheFulltextIndexerAndCurrentStore(): void
    {
        $this->givenDirect(['name' => 5]);
        $this->searchKeyProvider->method('derive')->willReturn('k');
        $resolver = $this->createMock(IndexNameResolverInterface::class);
        $resolver->expects(self::once())->method('getPublicAliasName')->with(Fulltext::INDEXER_ID, 1)
            ->willReturn('a_pub');
        $resolver->expects(self::never())->method('getAliasName');
        $this->indexNameResolver = $resolver;

        self::assertSame('a_pub', $this->rebuild()->getConfig()['collection']);
    }

    public function testQueryByIsTheIndexerFieldsIntersectedWithThePublicSetInProviderOrder(): void
    {
        $this->givenDirect(['description' => 1, 'sku' => 3, 'color_value' => 2, 'name' => 5, 'brand' => 4,
            'short_description' => 1]);
        $this->searchKeyProvider->method('derive')->willReturn('k');

        $config = $this->viewModel->getConfig();

        self::assertSame('sku,color_value,name,short_description', $config['queryBy']);
        self::assertSame('3,2,5,1', $config['queryByWeights']);
    }

    public function testDerivesThePublicKeyWithEmbeddedParams(): void
    {
        $this->givenDirect(['name' => 5, 'description' => 1]);

        $this->searchKeyProvider->expects(self::once())
            ->method('derive')
            ->with(1, 'public', [
                'include_fields' => 'name,sku,url_key,thumbnail,price,tax_class_id',
                'filter_by' => 'visibility:[3,4] && status:=1',
                'per_page' => 9,
                // bucket 997200 (floor(1e6 / 3600) * 3600) + 2 * 3600 + 3 d grace
                'expires_at' => 997200 + 7200 + 259200,
            ])
            ->willReturn('derived-scoped-key');

        self::assertSame('derived-scoped-key', $this->viewModel->getConfig()['apiKey']);
    }

    public function testExpiryIsStableWithinAnFpcBucketAndAdvancesAfterIt(): void
    {
        $this->givenDirect(['name' => 5]);
        $expiries = [];
        $this->searchKeyProvider->method('derive')->willReturnCallback(
            static function (int $storeId, string $role, array $params) use (&$expiries): string {
                $expiries[] = $params['expires_at'];

                return 'key-' . $params['expires_at'];
            }
        );

        foreach ([997200, 1_000_000, 1_000_799, 1_000_800] as $now) {
            $this->now = $now;
            $this->viewModel->getConfig();
        }

        self::assertSame($expiries[0], $expiries[1]);
        self::assertSame($expiries[0], $expiries[2]);
        self::assertSame($expiries[0] + 3600, $expiries[3]);
    }

    public function testExpiryFallsBackToTheDefaultFpcTtl(): void
    {
        $this->givenDirect(['name' => 5]);
        $this->fpcTtl = null;
        $this->now = 100_000;

        $this->searchKeyProvider->expects(self::once())
            ->method('derive')
            ->with(1, 'public', self::callback(static fn (array $params): bool =>
                $params['expires_at'] === 86400 + 2 * 86400 + 259200))
            ->willReturn('k');

        $this->viewModel->getConfig();
    }

    public function testExplicitGuestGroupGetsDirectMode(): void
    {
        $this->givenDirect(['name' => 5]);
        $this->customerGroup = '0';
        $this->searchKeyProvider->method('derive')->willReturn('k');

        self::assertSame('direct', $this->viewModel->getConfig()['mode']);
    }

    public function testLoggedInCustomerInDirectModeGetsProxyConfigOnly(): void
    {
        $this->givenDirect(['name' => 5]);
        $this->customerGroup = 1;
        $this->searchKeyProvider->expects(self::never())->method('derive');
        $this->guestPriceDisplay->expects(self::never())->method('get');

        $this->assertProxyOnly($this->viewModel->getConfig());
    }

    /**
     * @return array<string,array{0:?string,1:array<string,int>,2:?string}>
     */
    public static function unusableDirectProvider(): array
    {
        return [
            'no public key' => ['search.typesense.example', ['name' => 5], null],
            'no public host' => [null, ['name' => 5], 'k'],
            'no public searchable field' => ['search.typesense.example', ['description' => 1, 'brand' => 2], 'k'],
        ];
    }

    /**
     * @param array<string,int> $fields
     */
    #[DataProvider('unusableDirectProvider')]
    public function testUnusableDirectModeFallsBackToProxy(?string $host, array $fields, ?string $key): void
    {
        $this->givenDirect($fields, $host);
        $this->searchKeyProvider->method('derive')->willReturn($key);

        $this->assertProxyOnly($this->viewModel->getConfig());
    }

    public function testProxyModeNeverEmitsAKey(): void
    {
        $this->givenEnabled(Mode::PROXY);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->config->method('getPublicHost')->willReturn('search.typesense.example');
        $this->searchKeyProvider->expects(self::never())->method('derive');
        $this->guestPriceDisplay->expects(self::never())->method('get');

        $config = $this->viewModel->getConfig();

        $this->assertProxyOnly($config);
        self::assertSame(7, $config['minQueryLength']);
        self::assertSame(9, $config['resultLimit']);
        self::assertSame(150, $config['debounceMs']);
    }

    public function testJsonConfigIsValidJsonOfTheConfig(): void
    {
        $this->givenDirect(['name' => 5]);
        $this->searchKeyProvider->method('derive')->willReturn('k');

        // assertEquals: JSON drops the zero fraction of tax factors like 1.0.
        self::assertEquals($this->viewModel->getConfig(), json_decode($this->viewModel->getJsonConfig(), true));
    }

    public function testDisabledEmitsNothing(): void
    {
        $this->config->expects(self::atLeastOnce())->method('isEnabled')->with(1)->willReturn(false);

        self::assertNull($this->viewModel->getConfig());
        self::assertSame('', $this->viewModel->getJsonConfig());
    }

    public function testMissingProxyKeyEmitsNothing(): void
    {
        $this->givenEnabled(Mode::PROXY);
        $this->searchableFields->method('get')->willReturn(['name' => 10]);
        $this->proxyParentKey = null;

        self::assertNull($this->viewModel->getConfig());
    }

    public function testNoSearchableFieldsEmitsNothing(): void
    {
        $this->givenEnabled(Mode::DIRECT);
        $this->searchableFields->method('get')->willReturn([]);

        self::assertNull($this->viewModel->getConfig());
    }

    /**
     * @param array<string,mixed>|null $config
     */
    private function assertProxyOnly(?array $config): void
    {
        self::assertNotNull($config);
        self::assertSame('proxy', $config['mode']);
        self::assertSame(self::ENDPOINT, $config['endpoint']);
        foreach (['host', 'apiKey', 'collection', 'queryBy', 'priceField', 'currencyRate', 'taxFactors', 'imageUrlTemplate'] as $key) {
            self::assertArrayNotHasKey($key, $config);
        }
    }

    /**
     * @param array<string,int> $fields
     */
    private function givenDirect(array $fields, ?string $host = 'search.typesense.example'): void
    {
        $this->givenEnabled(Mode::DIRECT);
        $this->searchableFields->method('get')->with(1)->willReturn($fields);
        $this->config->method('getPublicHost')->willReturn($host);
    }

    private function givenEnabled(string $mode): void
    {
        // Store-scoped settings must be read for the current store (id 1).
        $this->config->method('isEnabled')->with(1)->willReturn(true);
        $this->config->method('getMode')->willReturn($mode);
        $this->config->method('isDirectMode')->willReturn($mode === Mode::DIRECT);
        $this->config->method('getMinQueryLength')->with(1)->willReturn(7);
        $this->config->method('getResultLimit')->with(1)->willReturn(9);
        $this->config->method('getDebounceMs')->with(1)->willReturn(150);
        $this->config->method('getDisplayFields')->with(1)->willReturn(['title' => true, 'sku' => false]);
    }

    private function rebuild(): InstantSearchConfig
    {
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->method('getValue')->willReturn(null);

        return new InstantSearchConfig(
            $this->config,
            $this->searchKeyProvider,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->storeManager,
            $this->mediaConfig,
            $this->productVisibility,
            $httpContext,
            $this->urlBuilder,
            $this->localeFormat,
            $this->scopeConfig,
            $this->dateTime,
            $this->guestPriceDisplay,
            $this->imageUrlBuilder
        );
    }
}
