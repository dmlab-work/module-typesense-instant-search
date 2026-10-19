<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;

/**
 * Core's connection settings with the admin key swapped for a scoped search-only key.
 */
class ScopedConnectionSettings implements ConnectionSettingsInterface
{
    /**
     * @param ConnectionSettingsInterface $inner configured connection (nodes, timeouts)
     * @param string $apiKey key sent instead of the admin key
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $inner,
        private readonly string $apiKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getNodes(): array
    {
        return $this->inner->getNodes();
    }

    /**
     * @inheritDoc
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @inheritDoc
     */
    public function getConnectionTimeout(): int
    {
        return $this->inner->getConnectionTimeout();
    }

    /**
     * @inheritDoc
     */
    public function getOperationTimeout(): int
    {
        return $this->inner->getOperationTimeout();
    }

    /**
     * @inheritDoc
     */
    public function getRetryCount(): int
    {
        return $this->inner->getRetryCount();
    }

    /**
     * @inheritDoc
     */
    public function getHealthCacheTtl(): int
    {
        return $this->inner->getHealthCacheTtl();
    }
}
