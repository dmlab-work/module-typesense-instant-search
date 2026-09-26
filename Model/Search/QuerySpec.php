<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Search;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;

/**
 * Builds the Typesense search request for one storefront term — the single source of truth for
 * *what* the proxy asks Typesense, so both frontend modes stay aligned with the indexed side.
 *
 * `query_by` / weights come from the indexer's {@see SearchableFieldsProviderInterface} (never
 * re-derived here), the collection alias from {@see IndexNameResolverInterface}. The filter mirrors
 * native quick search — search-visible products only ({@see Visibility::getVisibleInSearchIds()})
 * and enabled status — so the proxy can never surface catalog-only or disabled products.
 */
class QuerySpec
{
    /**
     * @param Config $config storefront settings (result limit)
     * @param SearchableFieldsProviderInterface $searchableFields indexer-owned `query_by` seam
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param Visibility $productVisibility search-visible visibility ids (Search, Both)
     */
    public function __construct(
        private readonly Config $config,
        private readonly SearchableFieldsProviderInterface $searchableFields,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly Visibility $productVisibility
    ) {
    }

    /**
     * The search request for a term in a store, or null when the store has no searchable fields.
     *
     * Returns `collection` (the alias to query) and `params` (the Typesense search parameters).
     *
     * @param string $term the raw storefront query
     * @param int $storeId
     * @return array{collection:string,params:array<string,mixed>}|null
     */
    public function build(string $term, int $storeId): ?array
    {
        $fields = $this->searchableFields->get($storeId);
        if ($fields === []) {
            return null;
        }

        return [
            'collection' => $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId),
            'params' => [
                'q' => $term,
                'query_by' => implode(',', array_keys($fields)),
                'query_by_weights' => implode(',', array_values($fields)),
                'filter_by' => $this->filterBy(),
                'per_page' => $this->config->getResultLimit(),
            ],
        ];
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
