<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Client\TypesenseClientFactory;
use DmLab\TypesenseInstantSearch\Model\Client\ProxyClientProvider;
use DmLab\TypesenseInstantSearch\Model\Client\ScopedConnectionSettings;
use DmLab\TypesenseInstantSearch\Model\Client\ScopedConnectionSettingsFactory;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ProxyClientProviderTest extends TestCase
{
    /** @var ScopedConnectionSettingsFactory&MockObject */
    private $settingsFactory;

    /** @var TypesenseClientFactory&MockObject */
    private $clientFactory;

    /** @var SearchKeyProvider&MockObject */
    private $keyProvider;

    /** @var ConnectionSettingsInterface&MockObject */
    private $settings;

    private ProxyClientProvider $provider;

    protected function setUp(): void
    {
        $this->settingsFactory = $this->createMock(ScopedConnectionSettingsFactory::class);
        $this->clientFactory = $this->createMock(TypesenseClientFactory::class);
        $this->keyProvider = $this->createMock(SearchKeyProvider::class);
        $this->settings = $this->createMock(ConnectionSettingsInterface::class);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_000_000);

        $this->provider = new ProxyClientProvider(
            $this->settingsFactory,
            $this->clientFactory,
            $this->keyProvider,
            $this->settings,
            $dateTime
        );
    }

    public function testDerivesTheProxyKeyWithEmbeddedParamsAndExpiry(): void
    {
        $embedded = ['include_fields' => 'name', 'filter_by' => 'status:=1', 'per_page' => 5];
        $this->keyProvider->expects(self::once())
            ->method('derive')
            ->with(2, 'proxy', $embedded + ['expires_at' => 1_000_300])
            ->willReturn('scoped-key');

        $scoped = new ScopedConnectionSettings($this->settings, 'scoped-key');
        $this->settingsFactory->expects(self::once())
            ->method('create')
            ->with(['inner' => $this->settings, 'apiKey' => 'scoped-key'])
            ->willReturn($scoped);

        $client = $this->createMock(TypesenseClient::class);
        $this->clientFactory->expects(self::once())
            ->method('create')
            ->with(['settings' => $scoped])
            ->willReturn($client);

        self::assertSame($client, $this->provider->get(2, $embedded));
    }

    public function testNullWhenTheStoreHasNoProxyKey(): void
    {
        $this->keyProvider->method('derive')->willReturn(null);
        $this->settingsFactory->expects(self::never())->method('create');
        $this->clientFactory->expects(self::never())->method('create');

        self::assertNull($this->provider->get(2, ['per_page' => 5]));
    }
}
