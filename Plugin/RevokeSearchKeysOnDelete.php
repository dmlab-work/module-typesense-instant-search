<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Plugin;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use Psr\Log\LoggerInterface;

/**
 * Revokes the parent keys of a deleted store view, or of every store of a deleted group or website.
 *
 * Key ids are read before the delete (the models' beforeDelete() clears their config rows in the
 * delete transaction) and revoked only once it succeeded. Failures are logged, never rethrown.
 */
class RevokeSearchKeysOnDelete
{
    /**
     * @param SearchKeyProvisioner $provisioner
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SearchKeyProvisioner $provisioner,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Revoke the keys of the deleted store(s) once the delete went through.
     *
     * @param AbstractDb $subject store, group or website resource model
     * @param callable $proceed
     * @param AbstractModel $object
     * @return AbstractDb
     */
    public function aroundDelete(AbstractDb $subject, callable $proceed, AbstractModel $object)
    {
        $keyIds = [];
        foreach ($this->storeIds($object) as $storeId) {
            try {
                $keyIds[$storeId] = $this->provisioner->keyIds($storeId);
            } catch (\Throwable $e) {
                $this->logFailure($storeId, $e);
            }
        }

        $result = $proceed($object);

        foreach ($keyIds as $storeId => $ids) {
            try {
                $this->provisioner->revokeIds($ids);
            } catch (\Throwable $e) {
                $this->logFailure($storeId, $e);
            }
        }

        return $result;
    }

    /**
     * Log a failed key revocation for a store.
     *
     * @param int $storeId
     * @param \Throwable $e
     */
    private function logFailure(int $storeId, \Throwable $e): void
    {
        $this->logger->critical(
            sprintf('DmLab instant search: revoking keys for store %d failed: %s', $storeId, $e->getMessage()),
            ['exception' => $e]
        );
    }

    /**
     * Store view ids of the deleted model, without the admin store.
     *
     * @param AbstractModel $object
     * @return int[]
     */
    private function storeIds(AbstractModel $object): array
    {
        if ($object instanceof Store) {
            $ids = [(int)$object->getId()];
        } elseif ($object instanceof Group || $object instanceof Website) {
            $ids = array_map('intval', $object->getStoreIds());
        } else {
            $ids = [];
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id !== Store::DEFAULT_STORE_ID));
    }
}
