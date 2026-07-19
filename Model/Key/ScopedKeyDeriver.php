<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Model\Key;

/**
 * Derives a Typesense scoped search-only key from a parent key — a pure local HMAC.
 *
 * This is the request-path half of the key machinery: no Typesense call, no admin key, no
 * network. Given the parent search-only key (created once out of band by
 * {@see SearchKeyManager}) and a set of embedded search parameters, it produces a key the
 * browser can use directly. The parent's collection restriction and `documents:search` action
 * ride along; the embedded params are enforced by Typesense on every search.
 *
 * The algorithm is Typesense's published scoped-key scheme (HMAC-SHA256 of the params JSON,
 * keyed by the parent, prefixed with the parent's first four characters), byte-for-byte the
 * same as the official clients, so a key derived here validates server-side without a round-trip.
 *
 * @api
 */
class ScopedKeyDeriver
{
    /** Parent-key characters Typesense uses to locate the parent when validating a scoped key. */
    private const KEY_PREFIX_LENGTH = 4;

    /**
     * Derive a scoped search-only key.
     *
     * Pure and deterministic: the same parent and params always yield the same key, and nothing
     * leaves the process.
     *
     * @param string $parentKey the parent search-only key value
     * @param array<string,mixed> $params embedded search parameters Typesense enforces
     * @return string the scoped key value, ready for the `x-typesense-api-key` header
     * @throws \JsonException when the params cannot be encoded
     */
    public function derive(string $parentKey, array $params): string
    {
        // Empty params must serialize as the JSON object `{}`, not the array `[]` PHP would emit,
        // to match Typesense's published scheme and its official clients — the byte layout the
        // server hashes. The storefront path derives with an `include_fields` allow-list.
        $paramsJson = $params === [] ? '{}' : json_encode($params, JSON_THROW_ON_ERROR);
        $digest = base64_encode(hash_hmac('sha256', $paramsJson, $parentKey, true));
        $keyPrefix = substr($parentKey, 0, self::KEY_PREFIX_LENGTH);

        return base64_encode($digest . $keyPrefix . $paramsJson);
    }
}
