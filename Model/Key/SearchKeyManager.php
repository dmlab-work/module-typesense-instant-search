<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Key;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;

/**
 * Typesense `/keys` API calls made with the admin key: create a parent search-only key, list, delete by id.
 *
 * Only ever called from provisioning (config save, store events, CLI) — never on the request path,
 * which only derives from the stored parent ({@see ScopedKeyDeriver}).
 *
 * @api
 */
class SearchKeyManager
{
    /** The only action a storefront key needs; it can search, nothing else. */
    private const SEARCH_ACTION = 'documents:search';

    /** `sprintf` format of every key description this module writes: role, then collection. */
    public const DESCRIPTION_FORMAT = 'dmlab-instant-search:%s:%s';

    /**
     * @param TypesenseClient $client core's generic client, called here with the admin key
     */
    public function __construct(
        private readonly TypesenseClient $client
    ) {
    }

    /**
     * Create a parent search-only key restricted to the given collections.
     *
     * @param string[] $collections collection names (or alias names) the key may search
     * @param string $description label shown in `GET /keys`
     * @return array{id:int,value:string} the key id and its full value (returned by Typesense only here)
     * @throws TypesenseException on a failed write
     * @throws \RuntimeException when the response carries no id or value
     */
    public function createParentSearchKey(array $collections, string $description): array
    {
        $response = $this->client->request('POST', '/keys', [
            'description' => $description,
            'actions' => [self::SEARCH_ACTION],
            'collections' => array_values($collections),
        ]);

        $id = $response['id'] ?? null;
        $value = $response['value'] ?? null;
        if (!is_int($id) || !is_string($value) || $value === '') {
            throw new \RuntimeException('Typesense did not return an id and value for the created search-only key.');
        }

        return ['id' => $id, 'value' => $value];
    }

    /**
     * All keys on the cluster, as `GET /keys` lists them (`id`, `description`, `value_prefix`, …).
     *
     * @return array<int,array<string,mixed>>
     * @throws TypesenseException on a failed read
     */
    public function listKeys(): array
    {
        $keys = $this->client->request('GET', '/keys')['keys'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_array')) : [];
    }

    /**
     * Delete one key by id; an already-missing key (404) counts as deleted.
     *
     * @param int $id Typesense key id
     * @throws TypesenseException on any other failure
     */
    public function deleteKey(int $id): void
    {
        try {
            $this->client->request('DELETE', '/keys/' . $id);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }
}
