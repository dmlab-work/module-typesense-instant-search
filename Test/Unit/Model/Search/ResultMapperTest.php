<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Search;

use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Price\TaxPriceCalculator;
use DmLab\TypesenseInstantSearch\Model\Search\ResultMapper;
use Magento\Catalog\Model\Product\Image\UrlBuilder as ImageUrlBuilder;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @var ImageUrlBuilder&MockObject */
    private $imageUrlBuilder;

    /** @var PriceCurrencyInterface&MockObject */
    private $priceCurrency;

    /** @var HttpContext&MockObject */
    private $httpContext;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var TaxPriceCalculator&MockObject */
    private $taxPriceCalculator;

    private ResultMapper $mapper;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->fieldNameResolver = $this->createMock(FieldNameResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->imageUrlBuilder = $this->createMock(ImageUrlBuilder::class);
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->httpContext = $this->createMock(HttpContext::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->taxPriceCalculator = $this->createMock(TaxPriceCalculator::class);
        $this->taxPriceCalculator->method('apply')->willReturnArgument(0);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_LINK)->willReturn('https://magento.loc/');
        $this->storeManager->method('getStore')->with(1)->willReturn($store);

        $this->imageUrlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $path, string $area): string =>
                'https://magento.loc/media/catalog/product/cache/' . $area . $path
        );
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
            $this->imageUrlBuilder,
            $this->priceCurrency,
            $this->httpContext,
            $this->scopeConfig,
            $this->taxPriceCalculator
        );
    }

    public function testDisplayTogglesAreReadForTheMappedStore(): void
    {
        $this->config->expects(self::once())->method('getDisplayFields')->with(1)->willReturn(
            ['title' => true, 'image' => false, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['name' => 'Running Shoe', 'url_key' => 'running-shoe']], 1);

        self::assertSame('Running Shoe', $items[0]['title']);
        self::assertArrayNotHasKey('sku', $items[0]);
    }

    public function testMapsAllFieldsWhenEveryDisplayToggleIsOn(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => true, 'price' => true, 'sku' => true]
        );
        $this->priceCurrency->expects(self::once())
            ->method('convertAndFormat')
            ->with(1499.0, false, PriceCurrencyInterface::DEFAULT_PRECISION, 1)
            ->willReturn('$1,499.00');

        $items = $this->mapper->map([[
            'name' => 'Running Shoe',
            'sku' => 'SHOE-1',
            'price_0_1' => 1499.0,
            'thumbnail' => '/r/u/running.jpg',
            'url_key' => 'running-shoe',
        ]], 1);

        self::assertCount(1, $items);
        self::assertSame('Running Shoe', $items[0]['title']);
        self::assertSame('SHOE-1', $items[0]['sku']);
        self::assertSame('$1,499.00', $items[0]['price']);
        self::assertSame('https://magento.loc/media/catalog/product/cache/product_thumbnail_image/r/u/running.jpg', $items[0]['image']);
        self::assertSame('https://magento.loc/running-shoe.html', $items[0]['url']);
    }

    public function testDisabledDisplayFieldsAreOmittedButUrlAlwaysPresent(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => false, 'price' => false, 'sku' => false]
        );
        $this->priceCurrency->expects(self::never())->method('convertAndFormat');

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

        $items = $this->mapper->map([[
            'url_key' => 'x',
            'thumbnail' => 'no_selection',
            'small_image' => 'no_selection',
            'image' => 'no_selection',
        ]], 1);

        self::assertArrayNotHasKey('image', $items[0]);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function imageRoleProvider(): array
    {
        return [
            'thumbnail first' => [
                ['thumbnail' => '/t.jpg', 'small_image' => '/s.jpg', 'image' => '/i.jpg'],
                '/t.jpg',
            ],
            'empty thumbnail → small_image' => [
                ['thumbnail' => '', 'small_image' => '/s.jpg', 'image' => '/i.jpg'],
                '/s.jpg',
            ],
            'placeholder thumbnail → small_image' => [
                ['thumbnail' => 'no_selection', 'small_image' => '/s.jpg', 'image' => '/i.jpg'],
                '/s.jpg',
            ],
            'only image' => [['small_image' => 'no_selection', 'image' => '/i.jpg'], '/i.jpg'],
        ];
    }

    /**
     * @param array<string,mixed> $roles
     */
    #[DataProvider('imageRoleProvider')]
    public function testImageIsTheFirstUsableRoleResizedForTheThumbnailArea(array $roles, string $expectedPath): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => true, 'price' => false, 'sku' => false]
        );
        $imageUrlBuilder = $this->createMock(ImageUrlBuilder::class);
        $imageUrlBuilder->expects(self::once())
            ->method('getUrl')
            ->with($expectedPath, ResultMapper::IMAGE_AREA)
            ->willReturn('https://magento.loc/media/catalog/product/cache/abc' . $expectedPath);
        $mapper = new ResultMapper(
            $this->config,
            $this->fieldNameResolver,
            $this->storeManager,
            $imageUrlBuilder,
            $this->priceCurrency,
            $this->httpContext,
            $this->scopeConfig,
            $this->taxPriceCalculator
        );

        $items = $mapper->map([['url_key' => 'x'] + $roles], 1);

        self::assertSame('product_thumbnail_image', ResultMapper::IMAGE_AREA);
        self::assertSame('https://magento.loc/media/catalog/product/cache/abc' . $expectedPath, $items[0]['image']);
    }

    public function testTitleFallsBackToSkuWhenNameMissing(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => true, 'image' => false, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['sku' => 'ONLY-SKU', 'url_key' => 'x']], 1);

        self::assertSame('ONLY-SKU', $items[0]['title']);
    }

    public function testProductUrlIsOmittedWhenUrlKeyMissing(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => false, 'sku' => false]
        );

        $items = $this->mapper->map([['sku' => 'NO-URL']], 1);

        self::assertArrayNotHasKey('url', $items[0]);
    }

    public function testPriceFieldFollowsTheCustomerGroupFromHttpContext(): void
    {
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->method('getValue')->with(CustomerContext::CONTEXT_GROUP)->willReturn(2);
        $mapper = new ResultMapper(
            $this->config,
            $this->fieldNameResolver,
            $this->storeManager,
            $this->imageUrlBuilder,
            $this->priceCurrency,
            $httpContext,
            $this->scopeConfig,
            $this->taxPriceCalculator
        );
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => true, 'sku' => false]
        );
        // The group-2 price field is read; the guest field (price_0_1) is ignored.
        $this->priceCurrency->expects(self::once())->method('convertAndFormat')
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
        $this->priceCurrency->expects(self::never())->method('convertAndFormat');

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

    public function testPriceIsTaxedThenConvertedAndFormattedNotJustFormatted(): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => true, 'sku' => false]
        );
        $taxPriceCalculator = $this->createMock(TaxPriceCalculator::class);
        $taxPriceCalculator->expects(self::once())->method('apply')->with(100.0, 2, 1)->willReturn(120.0);
        $this->priceCurrency->expects(self::never())->method('format');
        $this->priceCurrency->expects(self::once())->method('convertAndFormat')
            ->with(120.0, false, PriceCurrencyInterface::DEFAULT_PRECISION, 1)
            ->willReturn('€110.40');

        $items = $this->mapperWithTax($taxPriceCalculator)
            ->map([['url_key' => 'x', 'price_0_1' => 100.0, 'tax_class_id' => 2]], 1);

        self::assertSame('€110.40', $items[0]['price']);
    }

    /**
     * @return array<string,array{0:mixed,1:int}>
     */
    public static function taxClassIdProvider(): array
    {
        return [
            'int32[] from the main alias' => [[2], 2],
            'plain int' => [4, 4],
            'numeric string' => ['3', 3],
            'empty array' => [[], 0],
            'missing' => [null, 0],
        ];
    }

    #[DataProvider('taxClassIdProvider')]
    public function testTaxClassIdIsReadFromIntArrayOrMissing(mixed $taxClassId, int $expected): void
    {
        $this->config->method('getDisplayFields')->willReturn(
            ['title' => false, 'image' => false, 'price' => true, 'sku' => false]
        );
        $taxPriceCalculator = $this->createMock(TaxPriceCalculator::class);
        $taxPriceCalculator->expects(self::once())->method('apply')->with(50.0, $expected, 1)->willReturn(50.0);
        $this->priceCurrency->method('convertAndFormat')->willReturn('$50.00');

        $document = ['url_key' => 'x', 'price_0_1' => 50.0];
        if ($taxClassId !== null) {
            $document['tax_class_id'] = $taxClassId;
        }

        $this->mapperWithTax($taxPriceCalculator)->map([$document], 1);
    }

    public function testEmptyResultSetMapsToEmptyArray(): void
    {
        self::assertSame([], $this->mapper->map([], 1));
    }

    private function mapperWithTax(TaxPriceCalculator $taxPriceCalculator): ResultMapper
    {
        return new ResultMapper(
            $this->config,
            $this->fieldNameResolver,
            $this->storeManager,
            $this->imageUrlBuilder,
            $this->priceCurrency,
            $this->httpContext,
            $this->scopeConfig,
            $taxPriceCalculator
        );
    }
}
