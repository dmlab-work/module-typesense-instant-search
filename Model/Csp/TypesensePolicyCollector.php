<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Csp;

use DmLab\TypesenseInstantSearch\Model\Config;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Whitelists the configured public Typesense host in the `connect-src` CSP directive.
 *
 * Only `direct` mode has the browser query Typesense, so its host must be an allowed
 * `connect-src` origin. That host is admin config, not a literal, so it cannot ship in the
 * static `etc/csp_whitelist.xml`; this collector reads it at runtime and merges a
 * {@see FetchPolicy} into the composite policy set instead. Registered into
 * {@see \Magento\Csp\Model\CompositePolicyCollector::$collectors} via global di.xml.
 *
 * Fail-open by omission: in `proxy` mode (same-origin, no browser→Typesense call), when the
 * widget is disabled, or when no public host is set, the default policies are returned
 * untouched — nothing is added and nothing existing is removed.
 */
class TypesensePolicyCollector implements PolicyCollectorInterface
{
    private const DIRECTIVE = 'connect-src';

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager resolves the store whose enabled flag applies
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function collect(array $defaultPolicies = []): array
    {
        if (!$this->config->isEnabled((int)$this->storeManager->getStore()->getId())
            || !$this->config->isDirectMode()
        ) {
            return $defaultPolicies;
        }

        $host = $this->config->getPublicHost();
        if ($host === null) {
            return $defaultPolicies;
        }

        $defaultPolicies[] = new FetchPolicy(self::DIRECTIVE, false, [$host]);

        return $defaultPolicies;
    }
}
