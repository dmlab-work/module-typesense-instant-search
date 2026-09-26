<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Search;

use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Search\ResultMapper;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ResultMapperTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var FieldNameResolverInterface&MockObject */
    private $fieldNameResolver;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var MediaConfig&MockObject */
    private $mediaConfig;

    /** @var PriceCurrencyInterface&MockObject */
    private $priceCurrency;

    /** @var HttpContext&MockObject */
    private $httpContext;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    private ResultMapper $mapper;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->fieldNameResolver = $this->createMock(FieldNameResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->mediaConfig = $this->createMock(MediaConfig::class);
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->httpContext = $this->createMock(HttpContext::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_LINK)->willReturn('https://magento.loc/');
        $this->storeManager->method('getStore')->with(1)->willReturn($store);

        $this->mediaConfig->method('getBaseMediaUrl')->willReturn('https://magento.loc/media/catalog/product');
        $this->scopeConfig->method('getValue')->willReturn('.html');
        $this->httpContext->method('getValue')->with(CustomerContext::CONTEXT_GROUP)->willReturn(null);
        $this->fieldNameResolver->method('resolve')->with('price', self::anything())->willReturnCallback(
            static fn (string $code, array $context): string =>
                'price_' . (int)$context['customerGroupId'] . '_' . (int)$context['websiteId']
        );

        $this->mapper = new ResultMapper(
            $this->config,
            $this->fieldNameResolver,
            $this->storeManager,
            $this->mediaConfig,
            $this->priceCurrency,
            $this->httpContext,
            $this->scopeConfig
        );
    }

    public function testMapsAllFieldsWhenEveryDisplayToggleIsOn(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => true, 'price' => true, 'sku' => true]
        );
        $this->priceCurrency->expects(self::once())
            ->method('format')
            ->with(1499.0, false, PriceCurrencyInterface::DEFAULT_PRECISION, 1)
            ->willReturn('$1,499.00');

        $items = $this->mapper->map([[
            'name' => 'Running Shoe',
            'sku' => 'SHOE-1',
            'price_0_1' => 1499.0,
            'image' => '/r/u/running.jpg',
            'url_key' => 'running-shoe',
        ]], 1);

        self::assertCount(1, $items);
        self::assertSame('Running Shoe', $items[0]['title']);
        self::assertSame('SHOE-1', $items[0]['sku']);
        self::assertSame('$1,499.00', $items[0]['price']);
        self::assertSame('https://magento.loc/media/catalog/product/r/u/running.jpg', $items[0]['image']);
        self::assertSame('https://magento.loc/running-shoe.html', $items[0]['url']);
    }

    public function testDisabledDisplayFieldsAreOmittedButUrlAlwaysPresent(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => false, 'price' => false, 'sku' => false]
        );
        $this->priceCurrency->expects(self::never())->method('format');

        $items = $this->mapper->map([[
            'name' => 'Running Shoe',
            'sku' => 'SHOE-1',
            'price_0_1' => 1499.0,
            'image' => '/r/u/running.jpg',
            'url_key' => 'running-shoe',
        ]], 1);

        self::assertSame('Running Shoe', $items[0]['title']);
        self::assertArrayNotHasKey('sku', $items[0]);
        self::assertArrayNotHasKey('price', $items[0]);
        self::assertArrayNotHasKey('image', $items[0]);
        self::assertSame('https://magento.loc/running-shoe.html', $items[0]['url']);
    }

    public function testPlaceholderImageIsNotEmitted(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => true, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['url_key' => 'x', 'image' => 'no_selection']], 1);

        self::assertArrayNotHasKey('image', $items[0]);
    }

    public function testTitleFallsBackToSkuWhenNameMissing(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => false, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['sku' => 'ONLY-SKU', 'url_key' => 'x']], 1);

        self::assertSame('ONLY-SKU', $items[0]['title']);
    }

    public function testProductUrlIsStoreBaseWhenUrlKeyMissing(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['sku' => 'NO-URL']], 1);

        self::assertSame('https://magento.loc/', $items[0]['url']);
    }

    public function testPriceFieldFollowsTheCustomerGroupFromHttpContext(): void
    {
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->method('getValue')->with(CustomerContext::CONTEXT_GROUP)->willReturn(2);
        $mapper = new ResultMapper(
            $this->config,
            $this->fieldNameResolver,
            $this->storeManager,
            $this->mediaConfig,
            $this->priceCurrency,
            $httpContext,
            $this->scopeConfig
        );
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => true, 'sku' => false]
        );
        // The group-2 price field is read; the guest field (price_0_1) is ignored.
        $this->priceCurrency->expects(self::once())->method('format')
            ->with(99.0, false, PriceCurrencyInterface::DEFAULT_PRECISION, 1)
            ->willReturn('$99.00');

        $items = $mapper->map([['url_key' => 'x', 'price_2_1' => 99.0, 'price_0_1' => 10.0]], 1);

        self::assertSame('$99.00', $items[0]['price']);
    }

    public function testPriceIsOmittedWhenTheDocumentValueIsNotNumeric(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => true, 'sku' => false]
        );
        // A malformed fat document must not reach the currency formatter.
        $this->priceCurrency->expects(self::never())->method('format');

        $items = $this->mapper->map([['url_key' => 'x', 'price_0_1' => 'not-a-number']], 1);

        self::assertArrayNotHasKey('price', $items[0]);
    }

    public function testTitleIsOmittedWhenNameTitleAndSkuAreAllMissing(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => false, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['url_key' => 'x']], 1);

        self::assertArrayNotHasKey('title', $items[0]);
    }

    public function testEmptyResultSetMapsToEmptyArray(): void
    {
        self::assertSame([], $this->mapper->map([], 1));
    }
}
