<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Model\Search;

use MageDevGroup\TypesenseIndexer\Api\FieldNameResolverInterface;
use MageDevGroup\TypesenseInstantSearch\Model\Config;
use Magento\Catalog\Model\Product\Media\ConfigInterface as MediaConfig;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Maps Typesense hit documents to the dropdown items the proxy returns as JSON — the PHP twin of
 * the direct-mode JS mapper, so both modes render the identical row shape.
 *
 * The fat document already carries every rendered field (`name`, price, `sku`, `image`, `url_key`),
 * so nothing here touches MySQL. Only the fields whose display toggle is on ({@see Config::getDisplayFields()})
 * are emitted; `url` is always present so the row is clickable.
 */
class ResultMapper
{
    /** Config path for the product URL suffix (e.g. `.html`) appended after the url_key. */
    private const XML_PATH_PRODUCT_URL_SUFFIX = 'catalog/seo/product_url_suffix';

    /** Placeholder catalog images carry this sentinel, which must never become an <img src>. */
    private const NO_IMAGE = 'no_selection';

    /**
     * @param Config $config storefront display-field toggles
     * @param FieldNameResolverInterface $fieldNameResolver indexer-owned price field-name authority
     * @param StoreManagerInterface $storeManager resolves the store's base + media URLs
     * @param MediaConfig $mediaConfig catalog product media base URL for image hits
     * @param PriceCurrencyInterface $priceCurrency store-scoped price formatter
     * @param HttpContext $httpContext cache-safe source of the current customer group (FPC vary)
     * @param ScopeConfigInterface $scopeConfig reads the product URL suffix
     */
    public function __construct(
        private readonly Config $config,
        private readonly FieldNameResolverInterface $fieldNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly MediaConfig $mediaConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly HttpContext $httpContext,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Map a list of Typesense hit documents to dropdown items for the given store.
     *
     * @param array<int,array<string,mixed>> $documents raw hit documents (a hit's `document` payload)
     * @param int $storeId
     * @return array<int,array<string,mixed>>
     */
    public function map(array $documents, int $storeId): array
    {
        $displayFields = $this->config->getDisplayFields();
        $priceField = $this->resolvePriceField($storeId);
        $store = $this->storeManager->getStore($storeId);
        $baseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        $mediaBase = rtrim($this->mediaConfig->getBaseMediaUrl(), '/');
        $suffix = (string)$this->scopeConfig->getValue(
            self::XML_PATH_PRODUCT_URL_SUFFIX,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $items = [];
        foreach ($documents as $document) {
            $items[] = $this->mapDocument(
                $document,
                $displayFields,
                $priceField,
                $baseUrl,
                $mediaBase,
                $suffix,
                $storeId
            );
        }

        return $items;
    }

    /**
     * Map one hit document to a dropdown item, omitting fields whose display toggle is off.
     *
     * @param array<string,mixed> $document
     * @param array<string,bool> $displayFields
     * @param string $priceField the scoped price field name in the document
     * @param string $baseUrl store link base URL
     * @param string $mediaBase product media base URL (no trailing slash)
     * @param string $suffix product URL suffix
     * @param int $storeId
     * @return array<string,mixed>
     */
    private function mapDocument(
        array $document,
        array $displayFields,
        string $priceField,
        string $baseUrl,
        string $mediaBase,
        string $suffix,
        int $storeId
    ): array {
        $item = ['url' => $this->productUrl($document, $baseUrl, $suffix)];

        if (($displayFields['title'] ?? false) && ($title = $this->title($document)) !== '') {
            $item['title'] = $title;
        }

        if (($displayFields['sku'] ?? false) && isset($document['sku'])) {
            $item['sku'] = (string)$document['sku'];
        }

        if (($displayFields['price'] ?? false) && isset($document[$priceField]) && is_numeric($document[$priceField])) {
            $item['price'] = $this->priceCurrency->format(
                (float)$document[$priceField],
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $storeId
            );
        }

        if (($displayFields['image'] ?? false)) {
            $image = $this->imageUrl($document, $mediaBase);
            if ($image !== null) {
                $item['image'] = $image;
            }
        }

        return $item;
    }

    /**
     * The product title: `name`, falling back to `title` then `sku`, mirroring the JS mapper.
     *
     * @param array<string,mixed> $document
     */
    private function title(array $document): string
    {
        foreach (['name', 'title', 'sku'] as $field) {
            if (isset($document[$field]) && (string)$document[$field] !== '') {
                return (string)$document[$field];
            }
        }

        return '';
    }

    /**
     * The absolute product URL from the document's `url_key` plus the store's URL suffix.
     *
     * @param array<string,mixed> $document
     * @param string $baseUrl store link base URL
     * @param string $suffix product URL suffix
     */
    private function productUrl(array $document, string $baseUrl, string $suffix): string
    {
        $urlKey = isset($document['url_key']) ? trim((string)$document['url_key'], '/') : '';
        if ($urlKey === '') {
            return $baseUrl;
        }

        return $baseUrl . $urlKey . $suffix;
    }

    /**
     * The absolute image URL under the product media base, or null when the document has no image.
     *
     * @param array<string,mixed> $document
     * @param string $mediaBase product media base URL (no trailing slash)
     */
    private function imageUrl(array $document, string $mediaBase): ?string
    {
        $image = isset($document['image']) ? (string)$document['image'] : '';
        if ($image === '' || $image === self::NO_IMAGE || $mediaBase === '') {
            return null;
        }

        return $mediaBase . '/' . ltrim($image, '/');
    }

    /**
     * Resolve the scoped price field name for the current customer group and this store's website.
     *
     * Mirrors {@see \MageDevGroup\TypesenseInstantSearch\ViewModel\InstantSearchConfig}: the group
     * comes from the FPC-varied HTTP context so cached pages honour group/catalog-rule prices.
     *
     * @param int $storeId
     */
    private function resolvePriceField(int $storeId): string
    {
        $customerGroupId = (int)($this->httpContext->getValue(CustomerContext::CONTEXT_GROUP)
            ?? Group::NOT_LOGGED_IN_ID);

        return $this->fieldNameResolver->resolve('price', [
            'customerGroupId' => $customerGroupId,
            'websiteId' => (int)$this->storeManager->getStore($storeId)->getWebsiteId(),
        ]);
    }
}
