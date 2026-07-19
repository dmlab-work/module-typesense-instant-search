<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Model\Key;

use MageDevGroup\TypesenseIndexer\Api\IndexNameResolverInterface;
use MageDevGroup\TypesenseInstantSearch\Model\Config;
use MageDevGroup\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Auto-provisions the parent search-only key — no CLI, no paste box.
 *
 * The module already holds the admin key (Catalog Search config), so it creates the storefront
 * key itself instead of asking the admin to paste one. {@see self::ensure()} runs on config save
 * (via an observer) and is idempotent — it mints a key only in direct mode when none exists, so
 * flipping to direct provisions automatically and every later save is a no-op.
 *
 * The key is scoped to every store's collection alias ({@see IndexNameResolverInterface}) so one
 * key serves all store views. Only direct mode needs a key at all; proxy mode never provisions.
 */
class SearchKeyProvisioner
{
    /**
     * @param Config $config this module's storefront settings (mode)
     * @param SearchKeyProvider $keyProvider tells whether a parent key already exists
     * @param SearchKeyManager $keyManager mints the parent key via core's client (admin key)
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param StoreManagerInterface $storeManager enumerates the store views to scope the key
     * @param WriterInterface $configWriter persists the encrypted key
     * @param EncryptorInterface $encryptor encrypts the key before it is stored
     * @param TypeListInterface $cacheTypeList invalidates config cache after the write
     */
    public function __construct(
        private readonly Config $config,
        private readonly SearchKeyProvider $keyProvider,
        private readonly SearchKeyManager $keyManager,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * Provision the parent key when direct mode is on and none exists; otherwise do nothing.
     *
     * Idempotent and safe to call on every config save.
     *
     * @throws \MageDevGroup\TypesenseCore\Exception\ConfigurationException when the admin key is unset
     * @throws \MageDevGroup\TypesenseCore\Exception\TypesenseException on a failed write
     */
    public function ensure(): void
    {
        if (!$this->config->isDirectMode() || $this->keyProvider->hasParentKey()) {
            return;
        }

        $this->provision();
    }

    /**
     * Force a fresh parent key regardless of an existing one (the Regenerate action).
     *
     * The previous key is deleted on the Typesense side before the new one is minted, so scoped
     * keys derived from it stop working — regenerating actually revokes a leaked key.
     *
     * @throws \MageDevGroup\TypesenseCore\Exception\ConfigurationException when the admin key is unset
     * @throws \MageDevGroup\TypesenseCore\Exception\TypesenseException on a failed write
     */
    public function regenerate(): void
    {
        $this->provision();
    }

    /**
     * Retire any key this module created earlier, then mint a fresh one scoped to every store's
     * collection alias and store it encrypted.
     */
    private function provision(): void
    {
        $this->keyManager->deleteExistingSearchKeys();
        $key = $this->keyManager->createParentSearchKey($this->collectionAliases());

        $this->configWriter->save(
            SearchKeyProvider::XML_PATH_SEARCH_API_KEY,
            $this->encryptor->encrypt($key)
        );
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    /**
     * The distinct fulltext collection aliases across all store views.
     *
     * @return string[]
     */
    private function collectionAliases(): array
    {
        $aliases = [];
        foreach ($this->storeManager->getStores() as $store) {
            $aliases[] = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, (int)$store->getId());
        }

        return array_values(array_unique($aliases));
    }
}
