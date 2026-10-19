<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model;

use DmLab\TypesenseInstantSearch\Model\Key\ScopedKeyDeriver;
use DmLab\TypesenseInstantSearch\Model\Key\StoreKeyRepository;

/**
 * Request-path source of storefront keys: reads a store's parent key and derives a scoped key from it.
 * Never the admin key, never an API call.
 *
 * Fails closed: returns null when the store has no key for the role.
 */
class SearchKeyProvider
{
    /**
     * @param StoreKeyRepository $keyRepository
     * @param ScopedKeyDeriver $deriver
     */
    public function __construct(
        private readonly StoreKeyRepository $keyRepository,
        private readonly ScopedKeyDeriver $deriver
    ) {
    }

    /**
     * The store's parent key value for a role, or null when not provisioned.
     *
     * @param int $storeId
     * @param string $role {@see StoreKeyRepository::ROLE_PUBLIC} or {@see StoreKeyRepository::ROLE_PROXY}
     */
    public function getParentKey(int $storeId, string $role): ?string
    {
        return $this->keyRepository->get($storeId, $role)['value'] ?? null;
    }

    /**
     * A scoped key derived from the store's parent key for a role, or null when not provisioned.
     *
     * @param int $storeId
     * @param string $role
     * @param array<string,mixed> $params embedded search parameters Typesense enforces on the key
     * @throws \JsonException when the params cannot be encoded
     */
    public function derive(int $storeId, string $role, array $params): ?string
    {
        $parentKey = $this->getParentKey($storeId, $role);

        return $parentKey === null ? null : $this->deriver->derive($parentKey, $params);
    }
}
