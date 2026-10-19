<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Key;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Per-store parent keys (value encrypted + Typesense key id + scoped collection), stored at store scope.
 */
class StoreKeyRepository
{
    /** Key for the guest-only `<alias>_public` collection, derived into the page in direct mode. */
    public const ROLE_PUBLIC = 'public';

    /** Key for the main alias, derived server-side by the proxy controller. */
    public const ROLE_PROXY = 'proxy';

    public const ROLES = [self::ROLE_PUBLIC, self::ROLE_PROXY];

    private const PATH_PREFIX = 'dmlab_typesense/instant_search/';

    /**
     * Keys written in this process, by "<storeId>:<role>" (null = deleted): scope config keeps
     * loaded scopes in memory, so a later read would miss the write and mint an orphan key.
     *
     * @var array<string,array{id:int,value:string,collection:?string}|null>
     */
    private array $written = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param EncryptorInterface $encryptor
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * The store's parent key for a role, or null when not (fully) provisioned.
     *
     * @param int $storeId
     * @param string $role
     * @return array{id:int,value:string,collection:?string}|null collection is null for keys saved without one
     */
    public function get(int $storeId, string $role): ?array
    {
        if (array_key_exists($storeId . ':' . $role, $this->written)) {
            return $this->written[$storeId . ':' . $role];
        }
        $encrypted = $this->scopeConfig->getValue($this->valuePath($role), ScopeInterface::SCOPE_STORE, $storeId);
        $id = $this->scopeConfig->getValue($this->idPath($role), ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_string($encrypted) || $encrypted === '' || !is_numeric($id)) {
            return null;
        }

        $value = trim($this->encryptor->decrypt($encrypted));
        $collection = $this->scopeConfig->getValue(
            $this->collectionPath($role),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === '' ? null : [
            'id' => (int)$id,
            'value' => $value,
            'collection' => is_string($collection) && $collection !== '' ? $collection : null,
        ];
    }

    /**
     * Store a role's key (value encrypted) and the collection it is scoped to at store scope.
     *
     * @param int $storeId
     * @param string $role
     * @param int $id Typesense key id
     * @param string $value plain key value
     * @param string $collection collection the key is scoped to
     */
    public function save(int $storeId, string $role, int $id, string $value, string $collection): void
    {
        $this->configWriter->save(
            $this->valuePath($role),
            $this->encryptor->encrypt($value),
            ScopeInterface::SCOPE_STORES,
            $storeId
        );
        $this->configWriter->save($this->idPath($role), (string)$id, ScopeInterface::SCOPE_STORES, $storeId);
        $this->configWriter->save($this->collectionPath($role), $collection, ScopeInterface::SCOPE_STORES, $storeId);
        $this->written[$storeId . ':' . $role] = ['id' => $id, 'value' => $value, 'collection' => $collection];
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    /**
     * Remove a role's key rows from store scope (the Typesense key itself is not touched).
     *
     * @param int $storeId
     * @param string $role
     */
    public function delete(int $storeId, string $role): void
    {
        $this->configWriter->delete($this->valuePath($role), ScopeInterface::SCOPE_STORES, $storeId);
        $this->configWriter->delete($this->idPath($role), ScopeInterface::SCOPE_STORES, $storeId);
        $this->configWriter->delete($this->collectionPath($role), ScopeInterface::SCOPE_STORES, $storeId);
        $this->written[$storeId . ':' . $role] = null;
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    /**
     * Config path of a role's encrypted key value, e.g. `dmlab_typesense/instant_search/public_key`.
     *
     * @param string $role
     */
    private function valuePath(string $role): string
    {
        return self::PATH_PREFIX . $role . '_key';
    }

    /**
     * Config path of a role's Typesense key id, e.g. `dmlab_typesense/instant_search/public_key_id`.
     *
     * @param string $role
     */
    private function idPath(string $role): string
    {
        return self::PATH_PREFIX . $role . '_key_id';
    }

    /**
     * Config path of the collection a role's key is scoped to, e.g. `…/public_key_collection`.
     *
     * @param string $role
     */
    private function collectionPath(string $role): string
    {
        return self::PATH_PREFIX . $role . '_key_collection';
    }
}
