<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\ViewModel;

use MageDevGroup\TypesenseIndexer\Api\FieldNameResolverInterface;
use MageDevGroup\TypesenseIndexer\Api\IndexNameResolverInterface;
use MageDevGroup\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use MageDevGroup\TypesenseInstantSearch\Model\Config;
use MageDevGroup\TypesenseInstantSearch\Model\Config\Source\Mode;
use MageDevGroup\TypesenseInstantSearch\Model\SearchKeyProvider;
use MageDevGroup\TypesenseInstantSearch\ViewModel\InstantSearchConfig;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InstantSearchConfigTest extends TestCase
{
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

    /** @var FieldNameResolverInterface&MockObject */
    private $fieldNameResolver;

    /** @var MediaConfig&MockObject */
    private $mediaConfig;

    /** @var Visibility&MockObject */
    private $productVisibility;

    /** @var HttpContext&MockObject */
    private $httpContext;

    /** @var UrlInterface&MockObject */
    private $urlBuilder;

    /** @var FormatInterface&MockObject */
    private $localeFormat;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    private InstantSearchConfig $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->searchKeyProvider = $this->createMock(SearchKeyProvider::class);
        $this->searchableFields = $this->createMock(SearchableFieldsProviderInterface::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->fieldNameResolver = $this->createMock(FieldNameResolverInterface::class);
        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->productVisibility = $this->createMock(Visibility::class);
        $this->productVisibility->method('getVisibleInSearchIds')->willReturn([3, 4]);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getBaseUrl')->willReturn('https://magento.loc/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Price field named per scope, mirroring the indexer's `price_<group>_<website>` naming.
        $this->fieldNameResolver->method('resolve')->with('price', self::anything())->willReturnCallback(
            static fn (string $code, array $context): string =>
                'price_' . (int)$context['customerGroupId'] . '_' . (int)$context['websiteId']
        );
        $this->mediaConfig->method('getBaseMediaUrl')->willReturn('https://magento.loc/media/catalog/product');

        // Default to the guest group; individual tests override the HTTP-context group.
        $this->httpContext = $this->createMock(HttpContext::class);
        $this->httpContext->method('getValue')->willReturn(null);

        $this->urlBuilder = $this->createMock(UrlInterface::class);

        $this->localeFormat = $this->createMock(FormatInterface::class);
        $this->localeFormat->method('getPriceFormat')->willReturn(['pattern' => '$%s', 'precision' => 2]);

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')
            ->with('catalog/seo/product_url_suffix', self::anything(), self::anything())
            ->willReturn('.html');

        $this->viewModel = new InstantSearchConfig(
            $this->config,
            $this->searchKeyProvider,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->storeManager,
            $this->fieldNameResolver,
            $this->mediaConfig,
            $this->productVisibility,
            $this->httpContext,
            $this->urlBuilder,
            $this->localeFormat,
            $this->scopeConfig
        );
    }

    public function testEmitsFullConfigWhenEnabledAndKeyed(): void
    {
        $this->givenEnabledAndKeyed();

        $config = $this->viewModel->getConfig();

        self::assertSame('direct', $config['mode']);
        self::assertSame('search.typesense.example', $config['host']);
        self::assertSame('derived-scoped-key', $config['apiKey']);
        self::assertSame('typesense_catalogsearch_fulltext_1', $config['collection']);
        self::assertSame('price_0_1', $config['priceField']);
        self::assertSame(['pattern' => '$%s', 'precision' => 2], $config['priceFormat']);
        self::assertSame('https://magento.loc/media/catalog/product', $config['mediaUrl']);
        self::assertSame(7, $config['minQueryLength']);
        self::assertSame(9, $config['resultLimit']);
        self::assertSame(150, $config['debounceMs']);
        self::assertSame(['title' => true, 'sku' => false], $config['displayFields']);
        self::assertSame('https://magento.loc/', $config['baseUrl']);
        self::assertSame('.html', $config['productUrlSuffix']);
    }

    public function testQueryByAndWeightsComeFromTheIndexerContractNotReDerived(): void
    {
        $this->givenEnabled();
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->searchKeyProvider->method('getScopedSearchKey')->willReturn('derived-scoped-key');

        // The provider is asked for the current store's fields; the view model must not invent them.
        $this->searchableFields->expects(self::once())->method('get')->with(1)->willReturn(
            ['name' => 5, 'sku' => 3, 'description' => 1]
        );

        $config = $this->viewModel->getConfig();

        self::assertSame('name,sku,description', $config['queryBy']);
        self::assertSame('5,3,1', $config['queryByWeights']);
    }

    public function testResolvesAliasForTheFulltextIndexerAndCurrentStore(): void
    {
        $this->givenEnabled();
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->searchKeyProvider->method('getScopedSearchKey')->willReturn('derived-scoped-key');

        $this->indexNameResolver->expects(self::once())
            ->method('getAliasName')
            ->with(Fulltext::INDEXER_ID, 1)
            ->willReturn('typesense_catalogsearch_fulltext_1');

        self::assertSame('typesense_catalogsearch_fulltext_1', $this->viewModel->getConfig()['collection']);
    }

    public function testDerivesAScopedKeyNeverExposingTheParent(): void
    {
        $this->givenEnabled();
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');

        // The request path only derives — no admin key, no parent key reaches the browser config.
        $this->searchKeyProvider->expects(self::once())
            ->method('getScopedSearchKey')
            ->willReturn('derived-scoped-key');

        self::assertSame('derived-scoped-key', $this->viewModel->getConfig()['apiKey']);
    }

    public function testRestrictsTheDerivedKeyToQueryAndDisplayFields(): void
    {
        $this->givenEnabled();
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->searchableFields->method('get')->willReturn(['name' => 5, 'description' => 1]);

        // The public key must be scoped to only the fields the widget reads, so backend-only
        // attributes (e.g. `cost`) in the fat documents can never be pulled from the browser.
        $this->searchKeyProvider->expects(self::once())
            ->method('getScopedSearchKey')
            ->with([
                'include_fields' => 'name,description,title,sku,image,price_0_1',
                'filter_by' => 'visibility:[3,4] && status:=1',
                'per_page' => 9,
            ])
            ->willReturn('derived-scoped-key');

        self::assertSame('derived-scoped-key', $this->viewModel->getConfig()['apiKey']);
    }

    public function testCapsResultsInTheDerivedKeyToTheConfiguredLimit(): void
    {
        $this->givenEnabled();
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->searchableFields->method('get')->willReturn(['name' => 5]);

        // The limit is embedded in the key so a holder of the public scoped key cannot request more
        // hits per call than the widget renders (Typesense enforces embedded params) — no enumeration.
        $this->searchKeyProvider->expects(self::once())
            ->method('getScopedSearchKey')
            ->with(self::callback(static fn (array $params): bool => ($params['per_page'] ?? null) === 9))
            ->willReturn('derived-scoped-key');

        self::assertSame('derived-scoped-key', $this->viewModel->getConfig()['apiKey']);
    }

    public function testConstrainsTheDerivedKeyToSearchVisibleProducts(): void
    {
        $this->givenEnabled();
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->searchableFields->method('get')->willReturn(['name' => 5]);

        // The indexer indexes catalog-only products too; the key must filter them out server-side
        // so the browser cannot see products native quick search hides.
        $this->searchKeyProvider->expects(self::once())
            ->method('getScopedSearchKey')
            ->with(self::callback(static fn (array $params): bool =>
                ($params['filter_by'] ?? null) === 'visibility:[3,4] && status:=1'))
            ->willReturn('derived-scoped-key');

        self::assertSame('derived-scoped-key', $this->viewModel->getConfig()['apiKey']);
    }

    public function testPriceFieldFollowsTheCurrentCustomerGroupFromHttpContext(): void
    {
        $this->givenEnabled();
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->searchableFields->method('get')->willReturn(['name' => 5]);

        // A logged-in group-2 customer (the FPC varies on this context value) must see the group's
        // price field, both in the emitted config and in the key's include_fields scope.
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->method('getValue')->with(CustomerContext::CONTEXT_GROUP)->willReturn(2);

        $viewModel = new InstantSearchConfig(
            $this->config,
            $this->searchKeyProvider,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->storeManager,
            $this->fieldNameResolver,
            $this->mediaConfig,
            $this->productVisibility,
            $httpContext,
            $this->urlBuilder,
            $this->localeFormat,
            $this->scopeConfig
        );

        $this->searchKeyProvider->expects(self::once())
            ->method('getScopedSearchKey')
            ->with(self::callback(static fn (array $params): bool =>
                str_contains($params['include_fields'] ?? '', 'price_2_1')))
            ->willReturn('derived-scoped-key');

        self::assertSame('price_2_1', $viewModel->getConfig()['priceField']);
    }

    public function testJsonConfigIsValidJsonOfTheConfig(): void
    {
        $this->givenEnabledAndKeyed();

        $json = $this->viewModel->getJsonConfig();

        self::assertSame($this->viewModel->getConfig(), json_decode($json, true));
    }

    public function testDisabledEmitsNothing(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        self::assertNull($this->viewModel->getConfig());
        self::assertSame('', $this->viewModel->getJsonConfig());
    }

    public function testDirectModeMissingPublicHostEmitsNothing(): void
    {
        // In direct mode the browser needs a host to reach; without one the widget must not run.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getMode')->willReturn(Mode::DIRECT);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->config->method('getPublicHost')->willReturn(null);

        self::assertNull($this->viewModel->getConfig());
    }

    public function testDirectModeMissingParentKeyFailsClosed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getMode')->willReturn(Mode::DIRECT);
        $this->config->method('getPublicHost')->willReturn('search.typesense.example');
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->searchKeyProvider->method('getScopedSearchKey')->willReturn(null);

        self::assertNull($this->viewModel->getConfig());
        self::assertSame('', $this->viewModel->getJsonConfig());
    }

    public function testNoSearchableFieldsEmitsNothing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->searchableFields->method('get')->willReturn([]);

        self::assertNull($this->viewModel->getConfig());
    }

    public function testProxyModeEmitsEndpointAndNoKeyOrHost(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Mode::PROXY);
        $this->config->method('isDirectMode')->willReturn(false);
        $this->config->method('getMinQueryLength')->willReturn(4);
        $this->config->method('getResultLimit')->willReturn(6);
        $this->config->method('getDebounceMs')->willReturn(120);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->urlBuilder->method('getUrl')->with('typesense/ajax/search')
            ->willReturn('https://magento.loc/typesense/ajax/search');

        // Proxy is same-origin: no key/host derivation happens at all.
        $this->searchKeyProvider->expects(self::never())->method('getScopedSearchKey');

        $config = $this->viewModel->getConfig();

        self::assertSame('proxy', $config['mode']);
        self::assertSame('https://magento.loc/typesense/ajax/search', $config['endpoint']);
        self::assertSame(4, $config['minQueryLength']);
        self::assertSame(6, $config['resultLimit']);
        self::assertSame(120, $config['debounceMs']);
        // Nothing that would expose Typesense or a key reaches the page.
        self::assertArrayNotHasKey('host', $config);
        self::assertArrayNotHasKey('apiKey', $config);
        self::assertArrayNotHasKey('collection', $config);
        self::assertArrayNotHasKey('queryBy', $config);
    }

    public function testDirectModeShapeCarriesScopedKeyButNeverAdminOrParentKey(): void
    {
        $this->givenEnabledAndKeyed();

        $config = $this->viewModel->getConfig();

        self::assertSame('direct', $config['mode']);
        self::assertSame('derived-scoped-key', $config['apiKey']);
        // Only the derived scoped key is ever emitted — the module never surfaces the admin or
        // parent key, so no config value should equal a raw key name.
        self::assertArrayNotHasKey('parentKey', $config);
        self::assertArrayNotHasKey('adminKey', $config);
        self::assertNotContains('endpoint', array_keys($config));
    }

    private function givenEnabledAndKeyed(): void
    {
        $this->givenEnabled();
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('typesense_catalogsearch_fulltext_1');
        $this->searchKeyProvider->method('getScopedSearchKey')->willReturn('derived-scoped-key');
    }

    private function givenEnabled(): void
    {
        // The full-config assertions below all exercise direct mode; proxy mode is covered separately.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Mode::DIRECT);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getPublicHost')->willReturn('search.typesense.example');
        $this->config->method('getMinQueryLength')->willReturn(7);
        $this->config->method('getResultLimit')->willReturn(9);
        $this->config->method('getDebounceMs')->willReturn(150);
        $this->config->method('getDisplayFields')->willReturn(['title' => true, 'sku' => false]);
    }
}
