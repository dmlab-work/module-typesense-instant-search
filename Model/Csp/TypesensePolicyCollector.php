<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Csp;

use DmLab\TypesenseInstantSearch\Model\Config;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;

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
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function collect(array $defaultPolicies = []): array
    {
        if (!$this->config->isEnabled() || !$this->config->isDirectMode()) {
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
