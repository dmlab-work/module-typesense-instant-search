<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Key;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseInstantSearch\Model\Config;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Creates, rotates and revokes the per-store parent keys.
 *
 * Each store gets a proxy key on `<alias>` (both modes) and, in direct mode, a public key on
 * `<alias>_public`. Only the ids this module stored are ever deleted.
 */
class SearchKeyProvisioner
{
    public const STATUS_CREATED = 'created';
    public const STATUS_EXISTS = 'exists';
    public const STATUS_ROTATED = 'rotated';
    public const STATUS_REVOKED = 'revoked';

    /** Cleaning this type also purges Varnish via Magento_CacheInvalidate. */
    private const FULL_PAGE_CACHE = 'full_page';

    /**
     * @param Config $config
     * @param SearchKeyManager $keyManager
     * @param StoreKeyRepository $keyRepository
     * @param LegacyKeyMigrator $legacyKeyMigrator
     * @param IndexNameResolverInterface $indexNameResolver
     * @param StoreManagerInterface $storeManager
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly Config $config,
        private readonly SearchKeyManager $keyManager,
        private readonly StoreKeyRepository $keyRepository,
        private readonly LegacyKeyMigrator $legacyKeyMigrator,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * Create every missing key for one store, or all stores; existing keys are kept.
     *
     * A key scoped to another collection (the index prefix changed) is rotated.
     * A key of a role the current mode no longer uses (the public key in proxy mode) is revoked.
     *
     * @param int|null $storeId
     * @return array<int,array{store:string,role:string,status:string}>
     * @throws \DmLab\TypesenseCore\Exception\ConfigurationException when the admin key is unset
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on a failed write
     * @throws \RuntimeException when an unused key could not be deleted
     */
    public function ensure(?int $storeId = null): array
    {
        $legacyIds = $this->legacyKeyMigrator->findKeyIds();
        $changed = $legacyIds !== null;
        $results = [];
        $oldIds = [];
        $error = null;

        try {
            foreach ($this->stores($storeId) as $store) {
                $id = (int)$store->getId();
                foreach (StoreKeyRepository::ROLES as $role) {
                    if (!in_array($role, $this->roles(), true)) {
                        $oldId = $this->dropUnusedKey($id, $role);
                        if ($oldId !== null) {
                            $oldIds[] = $oldId;
                            $changed = true;
                            $results[] = $this->result($store, $role, self::STATUS_REVOKED);
                        }
                        continue;
                    }
                    $old = $this->keyRepository->get($id, $role);
                    if ($old !== null && $old['collection'] === $this->collection($id, $role)) {
                        $results[] = $this->result($store, $role, self::STATUS_EXISTS);
                        continue;
                    }
                    $this->createAndSave($id, $role);
                    $changed = true;
                    if ($old !== null) {
                        $oldIds[] = $old['id'];
                    }
                    $status = $old === null ? self::STATUS_CREATED : self::STATUS_ROTATED;
                    $results[] = $this->result($store, $role, $status);
                }
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->finish($changed, $legacyIds, $oldIds, $error);

        return $results;
    }

    /**
     * Rotate the keys of one store, or all stores: create new → save → clean caches → delete old ids.
     *
     * A key of a role the current mode no longer uses (the public key in proxy mode) is revoked.
     * A failed create leaves that store/role's old key stored and working; keys already rotated in
     * the run are still finalised (caches cleaned, old ids deleted) before the error propagates.
     *
     * @param int|null $storeId
     * @return array<int,array{store:string,role:string,status:string}>
     * @throws \DmLab\TypesenseCore\Exception\ConfigurationException when the admin key is unset
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on a failed write
     * @throws \RuntimeException when an old id could not be deleted
     */
    public function regenerate(?int $storeId = null): array
    {
        $legacyIds = $this->legacyKeyMigrator->findKeyIds();
        $changed = $legacyIds !== null;
        $results = [];
        $oldIds = [];
        $error = null;

        try {
            foreach ($this->stores($storeId) as $store) {
                $id = (int)$store->getId();
                foreach (StoreKeyRepository::ROLES as $role) {
                    if (!in_array($role, $this->roles(), true)) {
                        $oldId = $this->dropUnusedKey($id, $role);
                        if ($oldId !== null) {
                            $oldIds[] = $oldId;
                            $changed = true;
                            $results[] = $this->result($store, $role, self::STATUS_REVOKED);
                        }
                        continue;
                    }
                    $old = $this->keyRepository->get($id, $role);
                    $this->createAndSave($id, $role);
                    $changed = true;
                    if ($old !== null) {
                        $oldIds[] = $old['id'];
                    }
                    $status = $old === null ? self::STATUS_CREATED : self::STATUS_ROTATED;
                    $results[] = $this->result($store, $role, $status);
                }
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->finish($changed, $legacyIds, $oldIds, $error);

        return $results;
    }

    /**
     * Purge pages that may embed a replaced key, then revoke the legacy and old ids.
     *
     * @param bool $changed whether any key was created, rotated or dropped
     * @param int[]|null $legacyIds the 0.1.x key ids, null when no legacy row is stored
     * @param int[] $oldIds
     * @param \Throwable|null $error a failure raised earlier in the run
     * @throws \Throwable
     */
    private function finish(bool $changed, ?array $legacyIds, array $oldIds, ?\Throwable $error): void
    {
        if ($changed) {
            $this->cacheTypeList->cleanType(self::FULL_PAGE_CACHE);
        }
        if ($legacyIds !== null) {
            try {
                $this->legacyKeyMigrator->revoke($legacyIds);
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }
        $this->deleteKeys($oldIds, $error);
    }

    /**
     * Delete every given id, then rethrow: failed ids are named so they can be revoked by hand.
     *
     * @param int[] $ids
     * @param \Throwable|null $error a failure raised earlier in the run
     * @throws \Throwable
     */
    private function deleteKeys(array $ids, ?\Throwable $error): void
    {
        $failed = [];
        foreach ($ids as $id) {
            try {
                $this->keyManager->deleteKey($id);
            } catch (\Throwable $e) {
                $failed[] = $id;
                $error ??= $e;
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException(
                sprintf('Old key ids %s could not be revoked; delete them on Typesense.', implode(', ', $failed)),
                0,
                $error
            );
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Stored Typesense key ids of a store, all roles.
     *
     * @param int $storeId
     * @return int[]
     */
    public function keyIds(int $storeId): array
    {
        $ids = [];
        foreach (StoreKeyRepository::ROLES as $role) {
            $key = $this->keyRepository->get($storeId, $role);
            if ($key !== null) {
                $ids[] = $key['id'];
            }
        }

        return $ids;
    }

    /**
     * Delete keys on Typesense by id; their config rows are the caller's concern.
     *
     * @param int[] $ids
     * @throws \RuntimeException naming the ids that could not be deleted
     */
    public function revokeIds(array $ids): void
    {
        $this->deleteKeys($ids, null);
    }

    /**
     * Remove a stored key's rows; its Typesense id is returned for deletion after caches are cleaned.
     *
     * @param int $storeId
     * @param string $role
     * @return int|null the old key id, or null when nothing was stored
     */
    private function dropUnusedKey(int $storeId, string $role): ?int
    {
        $old = $this->keyRepository->get($storeId, $role);
        if ($old === null) {
            return null;
        }
        $this->keyRepository->delete($storeId, $role);

        return $old['id'];
    }

    /**
     * Mint a role's key on the store's collection and store it.
     *
     * @param int $storeId
     * @param string $role
     */
    private function createAndSave(int $storeId, string $role): void
    {
        $collection = $this->collection($storeId, $role);
        $key = $this->keyManager->createParentSearchKey(
            [$collection],
            sprintf(SearchKeyManager::DESCRIPTION_FORMAT, $role, $collection)
        );
        $this->keyRepository->save($storeId, $role, $key['id'], $key['value'], $collection);
    }

    /**
     * Collection a role's key is scoped to: `<alias>`, or `<alias>_public` for the public key.
     *
     * @param int $storeId
     * @param string $role
     */
    private function collection(int $storeId, string $role): string
    {
        return $role === StoreKeyRepository::ROLE_PUBLIC
            ? $this->indexNameResolver->getPublicAliasName(Fulltext::INDEXER_ID, $storeId)
            : $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);
    }

    /**
     * Roles to provision: the proxy key always, the public key only in direct mode.
     *
     * @return string[]
     */
    private function roles(): array
    {
        return $this->config->isDirectMode()
            ? [StoreKeyRepository::ROLE_PUBLIC, StoreKeyRepository::ROLE_PROXY]
            : [StoreKeyRepository::ROLE_PROXY];
    }

    /**
     * The given store, or every store view; never the admin store.
     *
     * @param int|null $storeId
     * @return StoreInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException for an unknown store id
     */
    private function stores(?int $storeId): array
    {
        if ($storeId === null) {
            return array_values($this->storeManager->getStores());
        }

        return $storeId === Store::DEFAULT_STORE_ID ? [] : [$this->storeManager->getStore($storeId)];
    }

    /**
     * One CLI/report line.
     *
     * @param StoreInterface $store
     * @param string $role
     * @param string $status
     * @return array{store:string,role:string,status:string}
     */
    private function result(StoreInterface $store, string $role, string $status): array
    {
        return ['store' => (string)$store->getCode(), 'role' => $role, 'status' => $status];
    }
}
