<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model;

use DmLab\TypesenseInstantSearch\Model\Config\Source\Mode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader over this module's storefront instant-search settings.
 *
 * Core's {@see \DmLab\TypesenseCore\Model\Config} owns the connection (server host,
 * admin key, timeouts); this reader owns only what the browser widget needs. The public host
 * is deliberately a separate path from the server host — the browser may reach Typesense at a
 * different address than PHP does. Mode and public host are default-scope only (keys and CSP are
 * per install); the widget settings are store-scoped and default to the current store when no id is passed.
 */
class Config
{
    /** Master switch for the storefront widget. */
    public const XML_PATH_ENABLED = 'dmlab_typesense/instant_search/enabled';

    /** Frontend query mode: proxy (default) or direct. */
    public const XML_PATH_MODE = 'dmlab_typesense/instant_search/mode';

    /** Publicly reachable Typesense host the browser connects to; may differ from the server host. */
    public const XML_PATH_PUBLIC_HOST = 'dmlab_typesense/instant_search/public_host';

    /** Characters typed before the first request. */
    public const XML_PATH_MIN_QUERY_LENGTH = 'dmlab_typesense/instant_search/min_query_length';

    /** Maximum suggestions in the dropdown. */
    public const XML_PATH_RESULT_LIMIT = 'dmlab_typesense/instant_search/result_limit';

    /** Milliseconds after the last keystroke before querying. */
    public const XML_PATH_DEBOUNCE_MS = 'dmlab_typesense/instant_search/debounce_ms';

    /** Whether the suggestion row shows the product title. */
    public const XML_PATH_DISPLAY_TITLE = 'dmlab_typesense/instant_search/display_title';

    /** Whether the suggestion row shows the product image. */
    public const XML_PATH_DISPLAY_IMAGE = 'dmlab_typesense/instant_search/display_image';

    /** Whether the suggestion row shows the price. */
    public const XML_PATH_DISPLAY_PRICE = 'dmlab_typesense/instant_search/display_price';

    /** Whether the suggestion row shows the SKU. */
    public const XML_PATH_DISPLAY_SKU = 'dmlab_typesense/instant_search/display_sku';

    private const DEFAULT_MIN_QUERY_LENGTH = 3;
    private const DEFAULT_RESULT_LIMIT = 5;
    private const DEFAULT_DEBOUNCE_MS = 200;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the storefront widget is switched on.
     *
     * @param int|null $storeId
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The frontend query mode, defaulting to proxy for any unrecognised value.
     *
     * Proxy keeps Typesense private behind a Magento endpoint; direct lets the browser query
     * Typesense with a scoped read-only key. Proxy is the safe default for self-hosted clusters.
     */
    public function getMode(): string
    {
        return $this->readNonEmptyString(self::XML_PATH_MODE) === Mode::DIRECT
            ? Mode::DIRECT
            : Mode::PROXY;
    }

    /**
     * Whether the browser queries Typesense directly (rather than through the Magento proxy).
     */
    public function isDirectMode(): bool
    {
        return $this->getMode() === Mode::DIRECT;
    }

    /**
     * Publicly reachable Typesense host for browser access, or null when unset.
     *
     * Trimmed to a bare host; a value carrying a scheme is rejected as null so callers never
     * build a double-scheme URL from it.
     */
    public function getPublicHost(): ?string
    {
        $host = $this->readNonEmptyString(self::XML_PATH_PUBLIC_HOST);
        if ($host === null || str_contains($host, '/')) {
            return null;
        }

        return $host;
    }

    /**
     * Characters that must be typed before a request is sent.
     *
     * @param int|null $storeId
     */
    public function getMinQueryLength(?int $storeId = null): int
    {
        return $this->readPositiveInt(self::XML_PATH_MIN_QUERY_LENGTH, self::DEFAULT_MIN_QUERY_LENGTH, $storeId);
    }

    /**
     * Maximum number of suggestions shown in the dropdown.
     *
     * @param int|null $storeId
     */
    public function getResultLimit(?int $storeId = null): int
    {
        return $this->readPositiveInt(self::XML_PATH_RESULT_LIMIT, self::DEFAULT_RESULT_LIMIT, $storeId);
    }

    /**
     * Milliseconds to wait after the last keystroke before querying.
     *
     * @param int|null $storeId
     */
    public function getDebounceMs(?int $storeId = null): int
    {
        return $this->readNonNegativeInt(self::XML_PATH_DEBOUNCE_MS, self::DEFAULT_DEBOUNCE_MS, $storeId);
    }

    /**
     * The display-field toggles as a field ⇒ bool map, in row-render order.
     *
     * @param int|null $storeId
     * @return array<string, bool>
     */
    public function getDisplayFields(?int $storeId = null): array
    {
        $paths = [
            'title' => self::XML_PATH_DISPLAY_TITLE,
            'image' => self::XML_PATH_DISPLAY_IMAGE,
            'price' => self::XML_PATH_DISPLAY_PRICE,
            'sku' => self::XML_PATH_DISPLAY_SKU,
        ];

        return array_map(
            fn (string $path): bool => $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId),
            $paths
        );
    }

    /**
     * Read a config value as a trimmed non-empty string, or null.
     *
     * @param string $path
     * @param string $scope
     * @param int|null $storeId
     */
    private function readNonEmptyString(
        string $path,
        string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ?int $storeId = null
    ): ?string {
        $value = $this->scopeConfig->getValue($path, $scope, $storeId);
        $value = is_scalar($value) ? trim((string)$value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Read a config value as an int of at least 1, falling back on anything else.
     *
     * A zero is a misconfiguration for these fields, not "no limit", so it takes the default too.
     *
     * @param string $path
     * @param int $default
     * @param int|null $storeId
     */
    private function readPositiveInt(string $path, int $default, ?int $storeId): int
    {
        $value = $this->readNonNegativeInt($path, $default, $storeId);

        return $value < 1 ? $default : $value;
    }

    /**
     * Read a config value as an int of at least 0, falling back on anything else.
     *
     * @param string $path
     * @param int $default
     * @param int|null $storeId
     */
    private function readNonNegativeInt(string $path, int $default, ?int $storeId): int
    {
        $raw = $this->readNonEmptyString($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || !preg_match('/^\d+$/', $raw)) {
            return $default;
        }

        return (int)$raw;
    }
}
