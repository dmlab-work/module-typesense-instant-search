<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Search;

use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the Typesense search request for one storefront term — the single source of truth for
 * *what* the proxy asks Typesense, so both frontend modes stay aligned with the indexed side.
 *
 * `query_by` / weights come from the indexer's {@see SearchableFieldsProviderInterface} (never
 * re-derived here), the collection alias from {@see IndexNameResolverInterface}. The filter mirrors
 * native quick search — search-visible products only ({@see Visibility::getVisibleInSearchIds()})
 * and enabled status — so the proxy can never surface catalog-only or disabled products.
 * `include_fields` limits hits to the rendered fields; `embedded` repeats the enforced params for the key.
 */
class QuerySpec
{
    /**
     * @param Config $config storefront settings (result limit)
     * @param SearchableFieldsProviderInterface $searchableFields indexer-owned `query_by` seam
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param Visibility $productVisibility search-visible visibility ids (Search, Both)
     * @param FieldNameResolverInterface $fieldNameResolver indexer-owned price field-name authority
     * @param HttpContext $httpContext cache-safe source of the current customer group
     * @param StoreManagerInterface $storeManager resolves the store's website
     */
    public function __construct(
        private readonly Config $config,
        private readonly SearchableFieldsProviderInterface $searchableFields,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly Visibility $productVisibility,
        private readonly FieldNameResolverInterface $fieldNameResolver,
        private readonly HttpContext $httpContext,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The search request for a term in a store, or null when the store has no searchable fields.
     *
     * Returns `collection` (the alias to query), `params` (the Typesense search parameters) and
     * `embedded` (the subset of `params` the proxy key enforces).
     *
     * @param string $term the raw storefront query
     * @param int $storeId
     * @return array{collection:string,params:array<string,mixed>,embedded:array<string,mixed>}|null
     */
    public function build(string $term, int $storeId): ?array
    {
        $fields = $this->searchableFields->get($storeId);
        if ($fields === []) {
            return null;
        }

        $embedded = [
            'include_fields' => 'name,sku,url_key,thumbnail,small_image,image,tax_class_id,'
                . $this->priceField($storeId),
            'filter_by' => $this->filterBy(),
            'per_page' => $this->config->getResultLimit($storeId),
        ];

        return [
            'collection' => $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId),
            'params' => [
                'q' => $term,
                'query_by' => implode(',', array_keys($fields)),
                'query_by_weights' => implode(',', array_values($fields)),
            ] + $embedded,
            'embedded' => $embedded,
        ];
    }

    /**
     * The price field for the current customer group and the store's website, as {@see ResultMapper} reads it.
     *
     * @param int $storeId
     */
    private function priceField(int $storeId): string
    {
        return $this->fieldNameResolver->resolve('price', [
            'customerGroupId' => (int)($this->httpContext->getValue(CustomerContext::CONTEXT_GROUP)
                ?? Group::NOT_LOGGED_IN_ID),
            'websiteId' => (int)$this->storeManager->getStore($storeId)->getWebsiteId(),
        ]);
    }

    /**
     * Server-side filter constraining hits to search-visible, enabled products.
     */
    private function filterBy(): string
    {
        return 'visibility:[' . implode(',', $this->productVisibility->getVisibleInSearchIds()) . ']'
            . ' && status:=' . Status::STATUS_ENABLED;
    }
}
