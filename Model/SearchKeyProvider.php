<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Model;

use MageDevGroup\TypesenseInstantSearch\Model\Key\ScopedKeyDeriver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * The request-path source of the key the browser gets: reads the encrypted parent key and
 * returns a *derived* scoped key. Never the admin key, never the parent key, never an API call.
 *
 * Fails closed: when no parent key is configured, {@see self::getScopedSearchKey()} returns null
 * so the widget config is not emitted and the storefront falls back to native search. The parent
 * key is auto-provisioned in direct mode by
 * {@see \MageDevGroup\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner}, not pasted.
 */
class SearchKeyProvider
{
    /** Parent search-only key, stored encrypted; auto-provisioned by the SearchKeyProvisioner. */
    public const XML_PATH_SEARCH_API_KEY = 'magedevgroup_typesense/instant_search/search_api_key';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param ScopedKeyDeriver $deriver
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly ScopedKeyDeriver $deriver
    ) {
    }

    /**
     * Whether a parent search-only key is configured.
     *
     * Drives fail-closed gating and the admin key-status field; involves no derivation.
     */
    public function hasParentKey(): bool
    {
        return $this->readParentKey() !== null;
    }

    /**
     * A scoped search-only key derived from the parent, or null when no parent key is set.
     *
     * The derivation is a pure local HMAC ({@see ScopedKeyDeriver}) — no admin key and no
     * Typesense call ever reach this path.
     *
     * @param array<string,mixed> $params embedded search parameters Typesense enforces on the key
     */
    public function getScopedSearchKey(array $params = []): ?string
    {
        $parentKey = $this->readParentKey();
        if ($parentKey === null) {
            return null;
        }

        return $this->deriver->derive($parentKey, $params);
    }

    /**
     * Read and decrypt the parent key, or null when unset.
     */
    private function readParentKey(): ?string
    {
        $encrypted = $this->scopeConfig->getValue(self::XML_PATH_SEARCH_API_KEY);
        $value = is_string($encrypted) && $encrypted !== '' ? trim($this->encryptor->decrypt($encrypted)) : '';

        return $value === '' ? null : $value;
    }
}
