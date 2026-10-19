<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseInstantSearch\Model\Key\LegacyKeyMigrator;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyManager;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LegacyKeyMigratorTest extends TestCase
{
    private const LEGACY_DESCRIPTION = 'DmLab instant search (storefront search-only)';

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var WriterInterface&MockObject */
    private $configWriter;

    /** @var EncryptorInterface&MockObject */
    private $encryptor;

    /** @var SearchKeyManager&MockObject */
    private $keyManager;

    private LegacyKeyMigrator $migrator;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->keyManager = $this->createMock(SearchKeyManager::class);
        $this->migrator = new LegacyKeyMigrator(
            $this->scopeConfig,
            $this->configWriter,
            $this->encryptor,
            $this->createMock(TypeListInterface::class),
            $this->keyManager
        );
    }

    public function testFindsOnlyTheKeyMatchingDescriptionAndPrefixWithoutSideEffects(): void
    {
        $this->givenLegacyKey('abcdEFGH');
        $this->keyManager->method('listKeys')->willReturn([
            ['id' => 1, 'description' => self::LEGACY_DESCRIPTION, 'value_prefix' => 'zzzz'],
            ['id' => 2, 'description' => 'other', 'value_prefix' => 'abcd'],
            ['id' => 3, 'description' => self::LEGACY_DESCRIPTION, 'value_prefix' => 'abcd'],
            ['id' => 4, 'description' => 'dmlab-instant-search:proxy:a', 'value_prefix' => 'abcd'],
        ]);
        $this->keyManager->expects(self::never())->method('deleteKey');
        $this->configWriter->expects(self::never())->method('delete');

        self::assertSame([3], $this->migrator->findKeyIds());
    }

    public function testAbsentLegacyKeyMakesNoApiCall(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->keyManager->expects(self::never())->method('listKeys');

        self::assertNull($this->migrator->findKeyIds());
    }

    public function testUndecryptableKeyFindsNothingWithoutTouchingTypesense(): void
    {
        $this->givenLegacyKey('');
        $this->keyManager->expects(self::never())->method('listKeys');

        self::assertSame([], $this->migrator->findKeyIds());
    }

    public function testRevokeDeletesTheIdsThenTheRow(): void
    {
        $log = [];
        $this->keyManager->method('deleteKey')->willReturnCallback(function (int $id) use (&$log): void {
            $log[] = "delete $id";
        });
        $this->configWriter->method('delete')->willReturnCallback(function (string $path) use (&$log): void {
            $log[] = "row-delete $path";
        });

        $this->migrator->revoke([3]);

        self::assertSame(['delete 3', 'row-delete dmlab_typesense/instant_search/search_api_key'], $log);
    }

    public function testRunsOncePerInstanceAfterARevoke(): void
    {
        $this->givenLegacyKey('abcdEFGH');
        $this->keyManager->expects(self::once())->method('listKeys')->willReturn([]);

        self::assertSame([], $this->migrator->findKeyIds());
        $this->migrator->revoke([]);
        self::assertNull($this->migrator->findKeyIds());
    }

    public function testFailedDeleteKeepsTheRowAndRetriesOnTheNextCall(): void
    {
        $this->givenLegacyKey('abcdEFGH');
        $this->keyManager->method('listKeys')->willReturn(
            [['id' => 3, 'description' => self::LEGACY_DESCRIPTION, 'value_prefix' => 'abcd']]
        );
        $this->keyManager->method('deleteKey')->willThrowException(new TypesenseException('down', 503));
        $this->configWriter->expects(self::never())->method('delete');

        try {
            $this->migrator->revoke($this->migrator->findKeyIds());
            self::fail('Expected the delete failure to propagate.');
        } catch (TypesenseException) {
        }

        self::assertSame([3], $this->migrator->findKeyIds());
    }

    private function givenLegacyKey(string $decrypted): void
    {
        $this->scopeConfig->method('getValue')
            ->with('dmlab_typesense/instant_search/search_api_key')
            ->willReturn('enc');
        $this->encryptor->method('decrypt')->with('enc')->willReturn($decrypted);
    }
}
