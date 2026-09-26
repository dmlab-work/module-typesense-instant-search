<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SearchKeyManagerTest extends TestCase
{
    /** @var TypesenseClient&\PHPUnit\Framework\MockObject\MockObject */
    private $client;

    private SearchKeyManager $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->manager = new SearchKeyManager($this->client);
    }

    public function testCreateParentSearchKeyBuildsTheSearchOnlyPayloadAndReturnsTheValue(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                '/keys',
                self::callback(static function (array $body): bool {
                    return $body['actions'] === ['documents:search']
                        && $body['collections'] === ['typesense_catalogsearch_fulltext_1']
                        && isset($body['description']);
                })
            )
            ->willReturn(['id' => 7, 'value' => 'derived-parent-value']);

        self::assertSame(
            'derived-parent-value',
            $this->manager->createParentSearchKey(['typesense_catalogsearch_fulltext_1'])
        );
    }

    public function testCreateParentSearchKeyReindexesCollectionKeys(): void
    {
        // A caller may pass a keyed array; the payload must send a JSON list, not an object.
        $this->client->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                '/keys',
                self::callback(static fn(array $body): bool => $body['collections'] === ['a', 'b'])
            )
            ->willReturn(['value' => 'v']);

        $this->manager->createParentSearchKey([3 => 'a', 9 => 'b']);
    }

    public function testCreateParentSearchKeyThrowsWhenNoValueIsReturned(): void
    {
        $this->client->method('request')->willReturn(['id' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->manager->createParentSearchKey(['collection']);
    }

    /**
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedValueProvider')]
    public function testCreateParentSearchKeyThrowsWhenValueIsEmptyOrNotAString($value): void
    {
        $this->client->method('request')->willReturn(['value' => $value]);

        $this->expectException(\RuntimeException::class);
        $this->manager->createParentSearchKey(['collection']);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public static function malformedValueProvider(): array
    {
        return [
            'empty string' => [''],
            'integer' => [123],
            'null' => [null],
            'array' => [['nested']],
        ];
    }

    public function testDeleteExistingSearchKeysDeletesOnlyThisModulesKeys(): void
    {
        $deleted = [];
        $this->client->method('request')->willReturnCallback(
            static function (string $method, string $path) use (&$deleted) {
                if ($method === 'GET' && $path === '/keys') {
                    return ['keys' => [
                        ['id' => 3, 'description' => 'DmLab instant search (storefront search-only)'],
                        ['id' => 4, 'description' => 'some other admin key'],
                        ['id' => 5, 'description' => 'DmLab instant search (storefront search-only)'],
                    ]];
                }
                $deleted[] = $method . ' ' . $path;

                return [];
            }
        );

        $this->manager->deleteExistingSearchKeys();

        self::assertSame(['DELETE /keys/3', 'DELETE /keys/5'], $deleted);
    }

    public function testDeleteExistingSearchKeysIsANoOpWhenNoKeysExist(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with('GET', '/keys')
            ->willReturn(['keys' => []]);

        $this->manager->deleteExistingSearchKeys();
    }
}
