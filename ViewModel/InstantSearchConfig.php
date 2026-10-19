<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\ViewModel;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Config\Source\Mode;
use DmLab\TypesenseInstantSearch\Model\Key\StoreKeyRepository;
use DmLab\TypesenseInstantSearch\Model\Price\GuestPriceDisplayProvider;
use DmLab\TypesenseInstantSearch\Model\Search\ResultMapper;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Image\UrlBuilder as ImageUrlBuilder;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the JSON config the storefront widget reads.
 *
 * Every config carries the same-origin proxy `endpoint`. Direct-mode extras (public host, a key
 * derived from the store's public parent key, the `<alias>_public` collection and query params) are
 * added only for NOT LOGGED IN visitors, so group prices never reach a public page; everyone else
 * gets `mode: "proxy"`. The FPC varies on the customer group, so each variant is cached separately.
 *
 * Fails closed: {@see self::getConfig()} returns null (and {@see self::getJsonConfig()} '') when
 * the widget is disabled, the store has no proxy key or the indexer reports no searchable fields.
 */
class InstantSearchConfig implements ArgumentInterface
{
    /** Fields the widget reads off a public-collection hit; the derived key pins the response to them. */
    private const PUBLIC_INCLUDE_FIELDS = ['name', 'sku', 'url_key', 'thumbnail', 'price', 'tax_class_id'];

    /** Searchable fields of the public collection (contract C2), besides any `*_value` field. */
    private const PUBLIC_SEARCHABLE_FIELDS = ['name', 'sku', 'short_description'];

    /** Price field of the public collection: the NOT LOGGED IN price for the store's website. */
    private const PUBLIC_PRICE_FIELD = 'price';

    /** Route path of the same-origin proxy endpoint. */
    private const PROXY_ENDPOINT_PATH = 'typesense/ajax/search';

    /** Path token the widget replaces with a hit's thumbnail path. */
    private const IMAGE_TOKEN = '/__DMLAB_IMAGE__';

    private const XML_PATH_FPC_TTL = 'system/full_page_cache/ttl';

    private const DEFAULT_FPC_TTL = 86400;

    /** Magento's default Varnish grace (3 d): a stale page may be served this long after its TTL. */
    private const FPC_GRACE = 259200;

    /**
     * @param Config $config this module's storefront settings
     * @param SearchKeyProvider $searchKeyProvider request-path source of the derived scoped key
     * @param SearchableFieldsProviderInterface $searchableFields indexer-owned `query_by` seam
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param StoreManagerInterface $storeManager resolves the storefront store scope
     * @param MediaConfig $mediaConfig catalog product media base URL (image fallback)
     * @param Visibility $productVisibility search-visible visibility ids (Search, Both)
     * @param HttpContext $httpContext cache-safe source of the current customer group (FPC vary)
     * @param UrlInterface $urlBuilder builds the same-origin proxy endpoint URL
     * @param FormatInterface $localeFormat store currency price-format for client-side formatting
     * @param ScopeConfigInterface $scopeConfig product URL suffix and FPC TTL
     * @param DateTime $dateTime
     * @param GuestPriceDisplayProvider $guestPriceDisplay currency rate + tax factors for public prices
     * @param ImageUrlBuilder $imageUrlBuilder resized thumbnail URL template for public hits
     */
    public function __construct(
        private readonly Config $config,
        private readonly SearchKeyProvider $searchKeyProvider,
        private readonly SearchableFieldsProviderInterface $searchableFields,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly MediaConfig $mediaConfig,
        private readonly Visibility $productVisibility,
        private readonly HttpContext $httpContext,
        private readonly UrlInterface $urlBuilder,
        private readonly FormatInterface $localeFormat,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly GuestPriceDisplayProvider $guestPriceDisplay,
        private readonly ImageUrlBuilder $imageUrlBuilder
    ) {
    }

    /**
     * The widget config, or null when the widget must not run.
     *
     * @return array<string,mixed>|null
     * @throws \JsonException when the key params cannot be encoded
     */
    public function getConfig(): ?array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        if (!$this->config->isEnabled($storeId)
            || $this->searchKeyProvider->getParentKey($storeId, StoreKeyRepository::ROLE_PROXY) === null
        ) {
            return null;
        }

        $fields = $this->searchableFields->get($storeId);
        if ($fields === []) {
            return null;
        }

        $base = [
            'mode' => Mode::PROXY,
            'minQueryLength' => $this->config->getMinQueryLength($storeId),
            'resultLimit' => $this->config->getResultLimit($storeId),
            'debounceMs' => $this->config->getDebounceMs($storeId),
            'baseUrl' => $store->getBaseUrl(),
            'productUrlSuffix' => (string)$this->scopeConfig->getValue(
                'catalog/seo/product_url_suffix',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'endpoint' => $this->urlBuilder->getUrl(self::PROXY_ENDPOINT_PATH),
        ];

        $direct = $this->isGuest() && $this->config->isDirectMode()
            ? $this->directConfig($store, $storeId, $fields)
            : null;

        return $direct === null ? $base : array_replace($base, $direct);
    }

    /**
     * The direct-mode extras, or null when direct mode is unusable (the page then uses the proxy).
     *
     * @param StoreInterface $store
     * @param int $storeId
     * @param array<string,int> $fields the indexer's `query_by` field ⇒ weight map
     * @return array<string,mixed>|null
     * @throws \JsonException
     */
    private function directConfig(StoreInterface $store, int $storeId, array $fields): ?array
    {
        $host = $this->config->getPublicHost();
        if ($host === null) {
            return null;
        }

        $queryBy = array_filter(
            $fields,
            static fn (string $field): bool => in_array($field, self::PUBLIC_SEARCHABLE_FIELDS, true)
                || str_ends_with($field, '_value'),
            ARRAY_FILTER_USE_KEY
        );
        if ($queryBy === []) {
            return null;
        }

        $filterBy = 'visibility:[' . implode(',', $this->productVisibility->getVisibleInSearchIds()) . ']'
            . ' && status:=' . Status::STATUS_ENABLED;

        // Typesense enforces embedded params, so the public key cannot widen fields, filter or page size.
        $apiKey = $this->searchKeyProvider->derive($storeId, StoreKeyRepository::ROLE_PUBLIC, [
            'include_fields' => implode(',', self::PUBLIC_INCLUDE_FIELDS),
            'filter_by' => $filterBy,
            'per_page' => $this->config->getResultLimit($storeId),
            'expires_at' => $this->keyExpiry(),
        ]);
        if ($apiKey === null) {
            return null;
        }

        $priceDisplay = $this->guestPriceDisplay->get($store);

        return [
            'mode' => Mode::DIRECT,
            'host' => $host,
            'apiKey' => $apiKey,
            'collection' => $this->indexNameResolver->getPublicAliasName(Fulltext::INDEXER_ID, $storeId),
            'queryBy' => implode(',', array_keys($queryBy)),
            'queryByWeights' => implode(',', array_values($queryBy)),
            'priceField' => self::PUBLIC_PRICE_FIELD,
            'priceFormat' => $this->localeFormat->getPriceFormat(null, $store->getCurrentCurrencyCode()),
            'currencyRate' => $priceDisplay['rate'],
            'taxFactors' => $priceDisplay['taxFactors'],
            'mediaUrl' => $this->mediaConfig->getBaseMediaUrl(),
            // The resize hash depends only on the area's params, so one template serves every path.
            'imageUrlTemplate' => $this->imageUrlBuilder->getUrl(self::IMAGE_TOKEN, ResultMapper::IMAGE_AREA),
            'displayFields' => $this->config->getDisplayFields($storeId),
        ];
    }

    /**
     * Whether the FPC variant being rendered is the NOT LOGGED IN one.
     */
    private function isGuest(): bool
    {
        $group = $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);

        return $group === null || (int)$group === Group::NOT_LOGGED_IN_ID;
    }

    /**
     * Key expiry bucketed by the FPC TTL.
     *
     * The derived key (and the cached page) is byte-identical within a bucket and outlives every page
     * cached in it, grace included.
     */
    private function keyExpiry(): int
    {
        $ttl = (int)$this->scopeConfig->getValue(self::XML_PATH_FPC_TTL);
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_FPC_TTL;
        }
        $bucket = intdiv($this->dateTime->gmtTimestamp(), $ttl) * $ttl;

        return $bucket + 2 * $ttl + self::FPC_GRACE;
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
