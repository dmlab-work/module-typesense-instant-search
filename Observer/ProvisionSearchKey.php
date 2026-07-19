<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Observer;

use MageDevGroup\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Provisions the search-only key when the Instant Search config section is saved.
 *
 * Bound to `admin_system_config_changed_section_magedevgroup_typesense`, so enabling direct mode
 * mints the key with no command to run ({@see SearchKeyProvisioner::ensure()} is idempotent, a
 * no-op in proxy mode or once a key exists).
 *
 * Failure surfaces at save time: if the admin key is missing (or Typesense rejects the write) the
 * provisioner throws, and this rethrows a {@see LocalizedException} so the admin sees the reason on
 * the config screen rather than a silent, persistent notice.
 */
class ProvisionSearchKey implements ObserverInterface
{
    /**
     * @param SearchKeyProvisioner $provisioner
     */
    public function __construct(
        private readonly SearchKeyProvisioner $provisioner
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when the key cannot be provisioned (surfaced on the config save)
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->provisioner->ensure();
        } catch (\Throwable $e) {
            throw new LocalizedException(
                new Phrase(
                    'Direct mode needs a Typesense search-only key but it could not be created: %1 '
                    . 'Set the Typesense admin API key under Catalog Search, then save again.',
                    [$e->getMessage()]
                ),
                $e
            );
        }
    }
}
