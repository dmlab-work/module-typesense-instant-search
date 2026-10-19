<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Client\TypesenseClientFactory;
use DmLab\TypesenseInstantSearch\Model\Key\StoreKeyRepository;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Builds the proxy's Typesense client, authenticated with a short-lived key derived from the
 * store's proxy parent key. The admin key never reaches the storefront request path.
 */
class ProxyClientProvider
{
    /** Lifetime (seconds) of the derived key; it is used for one request only. */
    private const KEY_TTL = 300;

    /**
     * @param ScopedConnectionSettingsFactory $settingsFactory
     * @param TypesenseClientFactory $clientFactory
     * @param SearchKeyProvider $keyProvider
     * @param ConnectionSettingsInterface $settings configured connection (nodes, timeouts)
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ScopedConnectionSettingsFactory $settingsFactory,
        private readonly TypesenseClientFactory $clientFactory,
        private readonly SearchKeyProvider $keyProvider,
        private readonly ConnectionSettingsInterface $settings,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * A client for the store's proxy key with the given params embedded, or null when not provisioned.
     *
     * @param int $storeId
     * @param array<string,mixed> $embedded search parameters Typesense enforces on the key
     * @throws \JsonException when the params cannot be encoded
     */
    public function get(int $storeId, array $embedded): ?TypesenseClient
    {
        $key = $this->keyProvider->derive(
            $storeId,
            StoreKeyRepository::ROLE_PROXY,
            $embedded + ['expires_at' => $this->dateTime->gmtTimestamp() + self::KEY_TTL]
        );
        if ($key === null) {
            return null;
        }

        return $this->clientFactory->create([
            'settings' => $this->settingsFactory->create(['inner' => $this->settings, 'apiKey' => $key]),
        ]);
    }
}
