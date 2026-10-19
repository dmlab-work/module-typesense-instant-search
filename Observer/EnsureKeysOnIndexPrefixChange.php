<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Re-ensures the keys when the catalog section save changes the index prefix: keys are scoped to the alias.
 */
class EnsureKeysOnIndexPrefixChange implements ObserverInterface
{
    public const INDEX_PREFIX_PATH = 'catalog/search/typesense_index_prefix';

    /**
     * @param ProvisionSearchKey $provisionSearchKey
     */
    public function __construct(
        private readonly ProvisionSearchKey $provisionSearchKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $changedPaths = (array)$observer->getEvent()->getData('changed_paths');
        if (in_array(self::INDEX_PREFIX_PATH, $changedPaths, true)) {
            $this->provisionSearchKey->execute($observer);
        }
    }
}
