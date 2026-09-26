<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Key;

use DmLab\TypesenseCore\Model\Client\TypesenseClient;

/**
 * Creates the parent search-only key — the one write in this module's key machinery.
 *
 * This is the create half, driven by {@see \DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner}:
 * a `POST /keys` through core's generic client using the admin key, minting a key restricted to
 * `documents:search` on the given collections. The returned value is stored encrypted; from then on
 * the request path only *derives* from it ({@see ScopedKeyDeriver}) — no admin key, no API call.
 *
 * Kept apart from derivation on purpose: an earlier draft had the request path create-and-cache,
 * which minted a fresh Typesense key every cache TTL and needed the admin key at render time.
 *
 * @api
 */
class SearchKeyManager
{
    /** The only action a storefront key needs; it can search, nothing else. */
    private const SEARCH_ACTION = 'documents:search';

    /** Human-readable label stored on the Typesense side so the key is identifiable in `GET /keys`. */
    private const KEY_DESCRIPTION = 'DmLab instant search (storefront search-only)';

    /**
     * @param TypesenseClient $client core's generic client, called here with the admin key
     */
    public function __construct(
        private readonly TypesenseClient $client
    ) {
    }

    /**
     * Create the parent search-only key restricted to the given collections.
     *
     * A single `POST /keys` write; returns the full key value, shown by Typesense exactly once,
     * for the caller to store encrypted.
     *
     * @param string[] $collections collection names (or alias names) the key may search
     * @return string the parent key value
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on a failed write
     * @throws \RuntimeException when the response carries no key value
     */
    public function createParentSearchKey(array $collections): string
    {
        $response = $this->client->request('POST', '/keys', [
            'description' => self::KEY_DESCRIPTION,
            'actions' => [self::SEARCH_ACTION],
            'collections' => array_values($collections),
        ]);

        $value = $response['value'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Typesense did not return a key value for the created search-only key.');
        }

        return $value;
    }

    /**
     * Delete every parent key this module previously created (matched by {@see self::KEY_DESCRIPTION},
     * so only this module's keys are touched).
     *
     * Regeneration calls this before minting a fresh key, so the old parent is actually retired on
     * the Typesense side: every scoped key derived from it — including any that leaked into page
     * source — stops working at once, instead of remaining valid forever.
     *
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on a failed read or delete
     */
    public function deleteExistingSearchKeys(): void
    {
        $response = $this->client->request('GET', '/keys');

        foreach ($response['keys'] ?? [] as $key) {
            if (($key['description'] ?? null) === self::KEY_DESCRIPTION && isset($key['id'])) {
                $this->client->request('DELETE', '/keys/' . (int)$key['id']);
            }
        }
    }
}
