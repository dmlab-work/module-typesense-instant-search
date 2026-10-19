<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SearchKeyManagerTest extends TestCase
{
    /** @var TypesenseClient&MockObject */
    private $client;

    private SearchKeyManager $manager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->manager = new SearchKeyManager($this->client);
    }

    public function testDescriptionFormatCarriesRoleAndCollection(): void
    {
        self::assertSame(
            'dmlab-instant-search:public:alias_1_public',
            sprintf(SearchKeyManager::DESCRIPTION_FORMAT, 'public', 'alias_1_public')
        );
    }

    public function testCreateSendsSearchOnlyPayloadWithDescriptionAndReturnsIdAndValue(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', '/keys', [
                'description' => 'dmlab-instant-search:proxy:alias_1',
                'actions' => ['documents:search'],
                'collections' => ['alias_1'],
            ])
            ->willReturn(['id' => 7, 'value' => 'parent-value']);

        self::assertSame(
            ['id' => 7, 'value' => 'parent-value'],
            $this->manager->createParentSearchKey(['alias_1'], 'dmlab-instant-search:proxy:alias_1')
        );
    }

    public function testCreateSendsCollectionsAsAList(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', '/keys', self::callback(
                static fn (array $body): bool => $body['collections'] === ['a', 'b']
            ))
            ->willReturn(['id' => 1, 'value' => 'v']);

        $this->manager->createParentSearchKey([3 => 'a', 9 => 'b'], 'd');
    }

    /**
     * @param array<string,mixed> $response
     */
    #[DataProvider('malformedResponseProvider')]
    public function testCreateThrowsOnMalformedResponse(array $response): void
    {
        $this->client->method('request')->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->manager->createParentSearchKey(['c'], 'd');
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function malformedResponseProvider(): array
    {
        return [
            'no value' => [['id' => 1]],
            'empty value' => [['id' => 1, 'value' => '']],
            'non-string value' => [['id' => 1, 'value' => 123]],
            'no id' => [['value' => 'v']],
            'non-int id' => [['id' => '1', 'value' => 'v']],
        ];
    }

    public function testListKeysReturnsTheKeysArray(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with('GET', '/keys')
            ->willReturn(['keys' => [['id' => 3, 'value_prefix' => 'abcd'], 'junk']]);

        self::assertSame([['id' => 3, 'value_prefix' => 'abcd']], $this->manager->listKeys());
    }

    public function testDeleteKeyDeletesByIdOnly(): void
    {
        $this->client->expects(self::once())
            ->method('request')
            ->with('DELETE', '/keys/12')
            ->willReturn(['id' => 12]);

        $this->manager->deleteKey(12);
    }

    public function testDeleteKeyIgnoresNotFound(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('Not found', 404));

        $this->manager->deleteKey(12);
        $this->addToAssertionCount(1);
    }

    public function testDeleteKeyRethrowsOtherErrors(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('Forbidden', 403));

        $this->expectException(TypesenseException::class);
        $this->manager->deleteKey(12);
    }

    public function testCreateAndDeleteNeverSweepTheKeyList(): void
    {
        $calls = [];
        $this->client->method('request')->willReturnCallback(
            static function (string $method, string $path) use (&$calls): array {
                $calls[] = $method . ' ' . $path;

                return ['id' => 1, 'value' => 'v'];
            }
        );

        $this->manager->createParentSearchKey(['c'], 'd');
        $this->manager->deleteKey(5);

        self::assertSame(['POST /keys', 'DELETE /keys/5'], $calls);
    }
}
