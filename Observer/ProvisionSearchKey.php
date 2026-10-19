<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Observer;

use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Ensures every store's parent keys when the Typesense config section is saved.
 *
 * Skipped while instant search is disabled in every store: the section also holds indexer settings.
 *
 * Failures are rethrown as a {@see LocalizedException}, so the admin sees the reason on the config screen.
 */
class ProvisionSearchKey implements ObserverInterface
{
    /**
     * @param SearchKeyProvisioner $provisioner
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly SearchKeyProvisioner $provisioner,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when the key cannot be provisioned (surfaced on the config save)
     */
    public function execute(Observer $observer): void
    {
        if (!$this->isEnabledInAnyStore()) {
            return;
        }

        try {
            $this->provisioner->ensure();
        } catch (\Throwable $e) {
            throw new LocalizedException(
                __(
                    'Instant search keys could not be created: %1 Set the Typesense admin API key and save again.',
                    $e->getMessage()
                ),
                $e
            );
        }
    }

    /**
     * Whether instant search is enabled in at least one store view.
     */
    private function isEnabledInAnyStore(): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->config->isEnabled((int)$store->getId())) {
                return true;
            }
        }

        return false;
    }
}
