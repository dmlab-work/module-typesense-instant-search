<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Search;

use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Price\TaxPriceCalculator;
use Magento\Catalog\Model\Product\Image\UrlBuilder as ImageUrlBuilder;
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
 * The fat document already carries every rendered field (`name`, price, `sku`, image roles, `url_key`),
 * so no product is loaded. Images are resized thumbnail cache URLs, not originals. Prices get the store's
 * tax display rule and are converted to the current currency.
 * Only the fields whose display toggle is on ({@see Config::getDisplayFields()}) are emitted; `url` is always
 * present so the row is clickable.
 */
class ResultMapper
{
    /** view.xml image id of the dropdown thumbnail (Luma: 75×75). */
    public const IMAGE_AREA = 'product_thumbnail_image';

    /** Image roles in order of preference; the first usable one is rendered. */
    private const IMAGE_ROLES = ['thumbnail', 'small_image', 'image'];

    /** Config path for the product URL suffix (e.g. `.html`) appended after the url_key. */
    private const XML_PATH_PRODUCT_URL_SUFFIX = 'catalog/seo/product_url_suffix';

    /** Placeholder catalog images carry this sentinel, which must never become an <img src>. */
    private const NO_IMAGE = 'no_selection';

    /**
     * @param Config $config storefront display-field toggles
     * @param FieldNameResolverInterface $fieldNameResolver indexer-owned price field-name authority
     * @param StoreManagerInterface $storeManager resolves the store's base + media URLs
     * @param ImageUrlBuilder $imageUrlBuilder resized product image cache URLs
     * @param PriceCurrencyInterface $priceCurrency converts base → current currency and formats
     * @param HttpContext $httpContext cache-safe source of the current customer group (FPC vary)
     * @param ScopeConfigInterface $scopeConfig reads the product URL suffix
     * @param TaxPriceCalculator $taxPriceCalculator applies the store's price display tax rule
     */
    public function __construct(
        private readonly Config $config,
        private readonly FieldNameResolverInterface $fieldNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageUrlBuilder $imageUrlBuilder,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly HttpContext $httpContext,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TaxPriceCalculator $taxPriceCalculator
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
        $displayFields = $this->config->getDisplayFields($storeId);
        $priceField = $this->resolvePriceField($storeId);
        $store = $this->storeManager->getStore($storeId);
        $baseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
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
     * @param string $suffix product URL suffix
     * @param int $storeId
     * @return array<string,mixed>
     */
    private function mapDocument(
        array $document,
        array $displayFields,
        string $priceField,
        string $baseUrl,
        string $suffix,
        int $storeId
    ): array {
        $item = [];
        $url = $this->productUrl($document, $baseUrl, $suffix);
        if ($url !== null) {
            $item['url'] = $url;
        }

        if (($displayFields['title'] ?? false) && ($title = $this->title($document)) !== '') {
            $item['title'] = $title;
        }

        if (($displayFields['sku'] ?? false) && isset($document['sku'])) {
            $item['sku'] = (string)$document['sku'];
        }

        if (($displayFields['price'] ?? false) && isset($document[$priceField]) && is_numeric($document[$priceField])) {
            $amount = $this->taxPriceCalculator->apply(
                (float)$document[$priceField],
                $this->taxClassId($document),
                $storeId
            );
            $item['price'] = $this->priceCurrency->convertAndFormat(
                $amount,
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $storeId
            );
        }

        if (($displayFields['image'] ?? false)) {
            $image = $this->imageUrl($document);
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
     * The product tax class id: an int, or the first element of the main alias's `int32[]`; missing → 0.
     *
     * @param array<string,mixed> $document
     */
    private function taxClassId(array $document): int
    {
        $value = $document['tax_class_id'] ?? 0;
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * The absolute product URL from the document's `url_key` plus the store's URL suffix, or null without one.
     *
     * @param array<string,mixed> $document
     * @param string $baseUrl store link base URL
     * @param string $suffix product URL suffix
     */
    private function productUrl(array $document, string $baseUrl, string $suffix): ?string
    {
        $urlKey = isset($document['url_key']) ? trim((string)$document['url_key'], '/') : '';
        if ($urlKey === '') {
            return null;
        }

        return $baseUrl . $urlKey . $suffix;
    }

    /**
     * The resized thumbnail URL of the first usable image role, or null when the document has none.
     *
     * @param array<string,mixed> $document
     */
    private function imageUrl(array $document): ?string
    {
        foreach (self::IMAGE_ROLES as $role) {
            $path = isset($document[$role]) && is_scalar($document[$role]) ? (string)$document[$role] : '';
            if ($path !== '' && $path !== self::NO_IMAGE) {
                return $this->imageUrlBuilder->getUrl($path, self::IMAGE_AREA);
            }
        }

        return null;
    }

    /**
     * Resolve the scoped price field name for the current customer group and this store's website.
     *
     * Mirrors {@see \DmLab\TypesenseInstantSearch\ViewModel\InstantSearchConfig}: the group
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
