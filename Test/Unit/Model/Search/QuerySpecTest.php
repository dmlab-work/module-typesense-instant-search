<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Search;

use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Search\QuerySpec;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class QuerySpecTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var SearchableFieldsProviderInterface&MockObject */
    private $searchableFields;

    /** @var IndexNameResolverInterface&MockObject */
    private $indexNameResolver;

    /** @var Visibility&MockObject */
    private $productVisibility;

    /** @var FieldNameResolverInterface&MockObject */
    private $fieldNameResolver;

    /** @var HttpContext&MockObject */
    private $httpContext;

    private QuerySpec $querySpec;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->searchableFields = $this->createMock(SearchableFieldsProviderInterface::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->productVisibility = $this->createMock(Visibility::class);
        $this->productVisibility->method('getVisibleInSearchIds')->willReturn([3, 4]);
        $this->fieldNameResolver = $this->createMock(FieldNameResolverInterface::class);
        $this->fieldNameResolver->method('resolve')->willReturnCallback(
            static fn (string $code, array $context): string =>
                $code . '_' . $context['customerGroupId'] . '_' . $context['websiteId']
        );
        $this->httpContext = $this->createMock(HttpContext::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(7);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->querySpec = new QuerySpec(
            $this->config,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->productVisibility,
            $this->fieldNameResolver,
            $this->httpContext,
            $storeManager
        );
    }

    public function testBuildsQueryFromTheIndexerContract(): void
    {
        $this->config->method('getResultLimit')->willReturn(5);
        $this->searchableFields->expects(self::once())->method('get')->with(1)->willReturn(
            ['name' => 5, 'sku' => 3, 'description' => 1]
        );
        $this->indexNameResolver->expects(self::once())
            ->method('getAliasName')
            ->with(Fulltext::INDEXER_ID, 1)
            ->willReturn('typesense_catalogsearch_fulltext_1');

        $spec = $this->querySpec->build('shoes', 1);

        self::assertSame('typesense_catalogsearch_fulltext_1', $spec['collection']);
        self::assertSame('shoes', $spec['params']['q']);
        self::assertSame('name,sku,description', $spec['params']['query_by']);
        self::assertSame('5,3,1', $spec['params']['query_by_weights']);
        self::assertSame(5, $spec['params']['per_page']);
    }

    public function testFilterConstrainsToSearchVisibleEnabledProducts(): void
    {
        $this->config->method('getResultLimit')->willReturn(5);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');

        $spec = $this->querySpec->build('shoes', 1);

        self::assertSame('visibility:[3,4] && status:=1', $spec['params']['filter_by']);
    }

    public function testPerPageFollowsTheConfiguredResultLimit(): void
    {
        $this->config->method('getResultLimit')->willReturn(9);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');

        self::assertSame(9, $this->querySpec->build('shoes', 1)['params']['per_page']);
    }

    public function testResultLimitIsReadForTheQueriedStore(): void
    {
        $this->config->expects(self::once())->method('getResultLimit')->with(3)->willReturn(4);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');

        self::assertSame(4, $this->querySpec->build('shoes', 3)['embedded']['per_page']);
    }

    public function testNoSearchableFieldsYieldsNull(): void
    {
        $this->searchableFields->method('get')->willReturn([]);
        $this->indexNameResolver->expects(self::never())->method('getAliasName');

        self::assertNull($this->querySpec->build('shoes', 1));
    }

    public function testIncludeFieldsListsTheRenderedFieldsAndTheGroupPrice(): void
    {
        $this->config->method('getResultLimit')->willReturn(5);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->httpContext->method('getValue')->with(CustomerContext::CONTEXT_GROUP)->willReturn(2);

        self::assertSame(
            'name,sku,url_key,thumbnail,small_image,image,tax_class_id,price_2_7',
            $this->querySpec->build('shoes', 1)['params']['include_fields']
        );
    }

    public function testMissingGroupFallsBackToNotLoggedInPrice(): void
    {
        $this->config->method('getResultLimit')->willReturn(5);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->httpContext->method('getValue')->willReturn(null);

        self::assertStringEndsWith(',price_0_7', $this->querySpec->build('shoes', 1)['params']['include_fields']);
    }

    public function testEmbeddedRepeatsTheEnforcedParams(): void
    {
        $this->config->method('getResultLimit')->willReturn(5);
        $this->searchableFields->method('get')->willReturn(['name' => 5]);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');

        $spec = $this->querySpec->build('shoes', 1);

        self::assertSame(['include_fields', 'filter_by', 'per_page'], array_keys($spec['embedded']));
        foreach ($spec['embedded'] as $param => $value) {
            self::assertSame($spec['params'][$param], $value);
        }
        self::assertArrayNotHasKey('q', $spec['embedded']);
    }
}
