<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Observer;

use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ensures parent keys for a saved store view, or for every store of a saved website, whatever its own
 * enabled flag, so enabling instant search there later needs no CLI run.
 *
 * Skipped while instant search is disabled in every store, like {@see ProvisionSearchKey}.
 *
 * Failures are logged, never rethrown, so a store save never fails on Typesense.
 */
class EnsureStoreSearchKeys implements ObserverInterface
{
    /**
     * @param SearchKeyProvisioner $provisioner
     * @param LoggerInterface $logger
     * @param Config $config
     * @param ReinitableConfigInterface $reinitableConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly SearchKeyProvisioner $provisioner,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if ($observer->getEvent()->getName() === 'store_add') {
            // The cached scope list lacks the new store, so its config would read as empty.
            $this->reinitableConfig->reinit();
        }
        if (!$this->isEnabledInAnyStore()) {
            return;
        }
        foreach ($this->storeIds($observer) as $storeId) {
            try {
                $this->provisioner->ensure($storeId);
            } catch (\Throwable $e) {
                $this->logger->critical(
                    sprintf('DmLab instant search: ensuring keys for store %d failed: %s', $storeId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
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

    /**
     * Store view ids carried by the event, without the admin store.
     *
     * @param Observer $observer
     * @return int[]
     */
    private function storeIds(Observer $observer): array
    {
        $event = $observer->getEvent();
        $store = $event->getData('store');
        if ($store instanceof StoreInterface) {
            $ids = [(int)$store->getId()];
        } else {
            $website = $event->getData('website');
            $ids = $website instanceof WebsiteInterface ? array_map('intval', $website->getStoreIds()) : [];
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id !== Store::DEFAULT_STORE_ID));
    }
}
