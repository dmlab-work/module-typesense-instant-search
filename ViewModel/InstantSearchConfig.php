<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\ViewModel;

use MageDevGroup\TypesenseIndexer\Api\FieldNameResolverInterface;
use MageDevGroup\TypesenseIndexer\Api\IndexNameResolverInterface;
use MageDevGroup\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use MageDevGroup\TypesenseInstantSearch\Model\Config;
use MageDevGroup\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the JSON config the storefront widget reads, per the active query mode.
 *
 * Both modes share the debounce/min-length/limit knobs; they differ only in the fetch contract:
 *  - **proxy** (default): the same-origin controller URL and nothing else — the browser hits Magento,
 *    which queries Typesense server-side with the admin key and returns already-mapped items. No
 *    host, no key, no per-field scope reaches the page.
 *  - **direct**: the public host + a *derived* scoped key ({@see SearchKeyProvider}) + the query
 *    parameters (the store's collection alias, `query_by` fields and weights, price/media/display
 *    hints) so the browser can query Typesense itself and map hits client-side.
 *
 * The two indexer-owned facts — the collection alias ({@see IndexNameResolverInterface}) and the
 * searchable `query_by` fields plus weights ({@see SearchableFieldsProviderInterface}) — are never
 * re-derived here, so the query side can't drift from the indexed side. The admin key and the parent
 * key are never emitted in either mode.
 *
 * Fails closed: {@see self::getConfig()} returns null — and {@see self::getJsonConfig()} the empty
 * string — whenever the widget must not run (disabled, no searchable fields, or, in direct mode, no
 * public host / no parent key). The template emits the widget config only when non-empty, so an
 * empty one leaves Magento's native autocomplete in place.
 */
class InstantSearchConfig implements ArgumentInterface
{
    /**
     * Fields the storefront widget reads off a hit document (JS `_hitTitle` / `_buildHitRow`).
     * The derived key restricts Typesense to these plus the `query_by` fields, so the backend-only
     * attributes the fat-document indexer writes into every document (e.g. `cost`) never reach the
     * browser — the key is public in page source, so its field scope is the disclosure boundary.
     *
     * Price is not listed here: the indexer names it `price_<customerGroupId>_<websiteId>`, never a
     * bare `price`, so its field is resolved per store scope in {@see self::getConfig()}.
     */
    private const BROWSER_DISPLAY_FIELDS = ['name', 'title', 'sku', 'image'];

    /** Route path of the same-origin proxy endpoint the browser hits in proxy mode. */
    private const PROXY_ENDPOINT_PATH = 'typesense/ajax/search';

    /**
     * @param Config $config this module's storefront settings
     * @param SearchKeyProvider $searchKeyProvider request-path source of the derived scoped key
     * @param SearchableFieldsProviderInterface $searchableFields indexer-owned `query_by` seam
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param StoreManagerInterface $storeManager resolves the storefront store scope
     * @param FieldNameResolverInterface $fieldNameResolver indexer-owned price field-name authority
     * @param MediaConfig $mediaConfig catalog product media base URL for image hits
     * @param Visibility $productVisibility search-visible visibility ids (Search, Both)
     * @param HttpContext $httpContext cache-safe source of the current customer group (FPC vary)
     * @param UrlInterface $urlBuilder builds the same-origin proxy endpoint URL
     * @param FormatInterface $localeFormat store currency price-format for client-side formatting
     * @param ScopeConfigInterface $scopeConfig reads the product URL suffix used to build hit product URLs
     */
    public function __construct(
        private readonly Config $config,
        private readonly SearchKeyProvider $searchKeyProvider,
        private readonly SearchableFieldsProviderInterface $searchableFields,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly FieldNameResolverInterface $fieldNameResolver,
        private readonly MediaConfig $mediaConfig,
        private readonly Visibility $productVisibility,
        private readonly HttpContext $httpContext,
        private readonly UrlInterface $urlBuilder,
        private readonly FormatInterface $localeFormat,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The widget config, or null when the widget must not run.
     *
     * @return array<string,mixed>|null
     */
    public function getConfig(): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        $fields = $this->searchableFields->get($storeId);
        if ($fields === []) {
            return null;
        }

        $base = [
            'mode' => $this->config->getMode(),
            'minQueryLength' => $this->config->getMinQueryLength(),
            'resultLimit' => $this->config->getResultLimit(),
            'debounceMs' => $this->config->getDebounceMs(),
            'baseUrl' => $store->getBaseUrl(),
            'productUrlSuffix' => (string)$this->scopeConfig->getValue(
                'catalog/seo/product_url_suffix',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
        ];

        // Proxy (default): the browser only needs the same-origin endpoint — Typesense stays private,
        // the query runs server-side, and items come back already mapped. No host, key or field scope.
        if (!$this->config->isDirectMode()) {
            $base['endpoint'] = $this->urlBuilder->getUrl(self::PROXY_ENDPOINT_PATH);

            return $base;
        }

        $direct = $this->directConfig($store, $storeId, $fields);

        return $direct === null ? null : $base + $direct;
    }

    /**
     * The direct-mode extras (host, derived scoped key, query params), or null when unusable.
     *
     * @param \Magento\Store\Api\Data\StoreInterface $store
     * @param int $storeId
     * @param array<string,int> $fields the indexer's `query_by` field ⇒ weight map
     * @return array<string,mixed>|null
     */
    private function directConfig($store, int $storeId, array $fields): ?array
    {
        $host = $this->config->getPublicHost();
        if ($host === null) {
            return null;
        }

        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);

        // The indexer names price per scope; show the current customer group's price for this store's
        // website — the same field name that group's documents carry. The group comes from the HTTP
        // context (not the session), which the FPC varies on, so each cached page and its embedded
        // key are scoped to the right group and logged-in group/catalog-rule prices are honoured.
        $customerGroupId = (int)($this->httpContext->getValue(CustomerContext::CONTEXT_GROUP)
            ?? Group::NOT_LOGGED_IN_ID);
        $priceField = $this->fieldNameResolver->resolve('price', [
            'customerGroupId' => $customerGroupId,
            'websiteId' => (int)$store->getWebsiteId(),
        ]);

        // Restrict the public key to the fields the widget renders (query_by + display fields).
        // Typesense enforces embedded params on every search, so a caller cannot widen `include_fields`
        // to read backend-only attributes like `cost` out of the fat documents.
        $includeFields = array_values(array_unique(
            array_merge(array_keys($fields), self::BROWSER_DISPLAY_FIELDS, [$priceField])
        ));

        // The indexer indexes every product (visibility is a query-time facet, not an index-time
        // filter), so — like native quick search and the proxy twin ({@see QuerySpec}) — the key
        // constrains results to search-visible, enabled products. Embedded in the key, Typesense
        // enforces it on every request; a caller with the public key cannot strip it to see
        // catalog-only or disabled items.
        $filterBy = 'visibility:[' . implode(',', $this->productVisibility->getVisibleInSearchIds()) . ']'
            . ' && status:=' . Status::STATUS_ENABLED;

        // Cap results in the key itself: Typesense enforces embedded params, so a holder of the public
        // scoped key cannot request up to 250 hits per call to enumerate the catalog — `per_page` is
        // pinned to the configured limit, the same ceiling the widget renders.
        $scopedKey = $this->searchKeyProvider->getScopedSearchKey([
            'include_fields' => implode(',', $includeFields),
            'filter_by' => $filterBy,
            'per_page' => $this->config->getResultLimit(),
        ]);
        if ($scopedKey === null) {
            return null;
        }

        return [
            'host' => $host,
            'apiKey' => $scopedKey,
            'collection' => $alias,
            'queryBy' => implode(',', array_keys($fields)),
            'queryByWeights' => implode(',', array_values($fields)),
            'priceField' => $priceField,
            'priceFormat' => $this->localeFormat->getPriceFormat(null, $store->getCurrentCurrencyCode()),
            'mediaUrl' => $this->mediaConfig->getBaseMediaUrl(),
            'displayFields' => $this->config->getDisplayFields(),
        ];
    }

    /**
     * The widget config as a JSON string, or the empty string when the widget must not run.
     *
     * @throws \JsonException when the config cannot be encoded
     */
    public function getJsonConfig(): string
    {
        $config = $this->getConfig();
        if ($config === null) {
            return '';
        }

        return json_encode($config, JSON_THROW_ON_ERROR);
    }
}
