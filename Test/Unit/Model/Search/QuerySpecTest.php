<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Search;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Search\QuerySpec;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
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

    private QuerySpec $querySpec;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->searchableFields = $this->createMock(SearchableFieldsProviderInterface::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->productVisibility = $this->createMock(Visibility::class);
        $this->productVisibility->method('getVisibleInSearchIds')->willReturn([3, 4]);

        $this->querySpec = new QuerySpec(
            $this->config,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->productVisibility
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

    public function testNoSearchableFieldsYieldsNull(): void
    {
        $this->searchableFields->method('get')->willReturn([]);
        $this->indexNameResolver->expects(self::never())->method('getAliasName');

        self::assertNull($this->querySpec->build('shoes', 1));
    }
}
