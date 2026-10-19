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

/**
 * Revokes the single 0.1.x default-scope key and drops its config row.
 *
 * Matches on description **and** value prefix, so a same-described key of another install sharing
 * the cluster is never touched. Finding and revoking are split so the caller can purge cached pages
 * that still embed the key in between.
 */
class LegacyKeyMigrator
{
    public const XML_PATH_LEGACY_KEY = 'dmlab_typesense/instant_search/search_api_key';

    private const LEGACY_DESCRIPTION = 'DmLab instant search (storefront search-only)';

    private const KEY_PREFIX_LENGTH = 4;

    /** @var bool set once the legacy row has been handled in this process */
    private bool $done = false;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param EncryptorInterface $encryptor
     * @param TypeListInterface $cacheTypeList
     * @param SearchKeyManager $keyManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly SearchKeyManager $keyManager
    ) {
    }

    /**
     * Ids of the legacy key on Typesense, or null when no legacy row is stored.
     *
     * @return int[]|null empty when the stored value cannot be decrypted
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on a failed list
     */
    public function findKeyIds(): ?array
    {
        if ($this->done) {
            return null;
        }

        $encrypted = $this->scopeConfig->getValue(self::XML_PATH_LEGACY_KEY);
        if (!is_string($encrypted) || $encrypted === '') {
            $this->done = true;

            return null;
        }

        $value = trim($this->encryptor->decrypt($encrypted));
        if ($value === '') {
            return [];
        }

        $ids = [];
        $prefix = substr($value, 0, self::KEY_PREFIX_LENGTH);
        foreach ($this->keyManager->listKeys() as $key) {
            if (($key['description'] ?? null) === self::LEGACY_DESCRIPTION
                && ($key['value_prefix'] ?? null) === $prefix
                && isset($key['id'])
            ) {
                $ids[] = (int)$key['id'];
            }
        }

        return $ids;
    }

    /**
     * Delete the legacy key ids, then drop the row; a failed delete keeps the row for a retry.
     *
     * @param int[] $ids from {@see self::findKeyIds()}
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on a failed delete
     */
    public function revoke(array $ids): void
    {
        foreach ($ids as $id) {
            $this->keyManager->deleteKey($id);
        }

        $this->configWriter->delete(self::XML_PATH_LEGACY_KEY);
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
        $this->done = true;
    }
}
