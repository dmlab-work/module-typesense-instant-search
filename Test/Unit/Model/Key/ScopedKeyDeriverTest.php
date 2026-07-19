<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\Model\Key;

use MageDevGroup\TypesenseInstantSearch\Model\Key\ScopedKeyDeriver;
use PHPUnit\Framework\TestCase;

class ScopedKeyDeriverTest extends TestCase
{
    private ScopedKeyDeriver $deriver;

    protected function setUp(): void
    {
        $this->deriver = new ScopedKeyDeriver();
    }

    public function testDeriveMatchesTypesensesPublishedScheme(): void
    {
        $parent = 'parent-search-key';
        $params = ['filter_by' => 'store_id:1'];

        // Recompute the published algorithm independently: HMAC-SHA256 of the params JSON keyed by
        // the parent, base64'd, then prefixed with the parent's first four chars, then base64'd.
        $paramsJson = json_encode($params);
        $digest = base64_encode(hash_hmac('sha256', $paramsJson, $parent, true));
        $expected = base64_encode($digest . substr($parent, 0, 4) . $paramsJson);

        self::assertSame($expected, $this->deriver->derive($parent, $params));
    }

    public function testDeriveEncodesEmptyParamsAsAJsonObject(): void
    {
        // The storefront path always derives with empty params. Typesense's scheme embeds the
        // params as the JSON object `{}`; PHP's json_encode([]) would emit `[]`, diverging from
        // the official clients. The embedded params JSON is the tail of the base64-decoded key.
        $decoded = base64_decode($this->deriver->derive('abcd1234', []), true);

        self::assertIsString($decoded);
        self::assertStringEndsWith('{}', $decoded);
    }

    public function testDeriveIsStableForTheSameParentAndParams(): void
    {
        $params = ['query_by' => 'name'];

        self::assertSame(
            $this->deriver->derive('abcd1234', $params),
            $this->deriver->derive('abcd1234', $params)
        );
    }

    public function testDeriveChangesWithParams(): void
    {
        self::assertNotSame(
            $this->deriver->derive('abcd1234', ['filter_by' => 'store_id:1']),
            $this->deriver->derive('abcd1234', ['filter_by' => 'store_id:2'])
        );
    }

    public function testDeriveNeverReturnsTheParentKey(): void
    {
        // The whole point: what leaves the process is a derived key, not the parent.
        $parent = 'super-secret-parent';

        self::assertNotSame($parent, $this->deriver->derive($parent, []));
    }

    public function testDeriveEmbedsTheParentPrefixSoTypesenseCanLocateTheParent(): void
    {
        $parent = 'WXYZsecret';

        $decoded = base64_decode($this->deriver->derive($parent, []), true);
        self::assertIsString($decoded);
        // digest is a 44-char base64 of 32 raw bytes; the next four bytes are the parent prefix.
        self::assertSame('WXYZ', substr($decoded, 44, 4));
    }
}
