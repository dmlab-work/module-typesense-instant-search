<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Controller\Ajax;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseInstantSearch\Controller\Ajax\Search;
use DmLab\TypesenseInstantSearch\Model\Client\ProxyClientProvider;
use DmLab\TypesenseInstantSearch\Model\ClientIp;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\RateGuard;
use DmLab\TypesenseInstantSearch\Model\Search\Availability;
use DmLab\TypesenseInstantSearch\Model\Search\QuerySpec;
use DmLab\TypesenseInstantSearch\Model\Search\ResultMapper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SearchTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var QuerySpec&MockObject */
    private $querySpec;

    /** @var ResultMapper&MockObject */
    private $resultMapper;

    /** @var TypesenseClient&MockObject */
    private $client;

    /** @var ProxyClientProvider&MockObject */
    private $clientProvider;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var RequestInterface&MockObject */
    private $request;

    /** @var JsonFactory&MockObject */
    private $resultJsonFactory;

    /** @var ClientIp&MockObject */
    private $clientIp;

    /** @var RateGuard&MockObject */
    private $rateGuard;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var Availability&MockObject */
    private $availability;

    /** @var array<string,mixed> data handed to the JSON result. */
    private array $captured = [];

    /** @var Search subject under test. */
    private Search $controller;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->querySpec = $this->createMock(QuerySpec::class);
        $this->resultMapper = $this->createMock(ResultMapper::class);
        $this->client = $this->createMock(TypesenseClient::class);
        $this->clientProvider = $this->createMock(ProxyClientProvider::class);
        $this->clientProvider->method('get')->willReturn($this->client);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->resultJsonFactory = $this->createMock(JsonFactory::class);
        $this->clientIp = $this->createMock(ClientIp::class);
        $this->rateGuard = $this->createMock(RateGuard::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->availability = $this->createMock(Availability::class);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->captured = $data;

            return $json;
        });
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller = $this->controllerWith($this->clientProvider);
    }

    /**
     * A store mock resolving to the given id.
     *
     * @return StoreInterface&MockObject
     */
    private function store(int $id): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);

        return $store;
    }

    public function testMapsHitsToJsonForAValidTerm(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->clientIp->method('get')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willReturn($this->store(1));

        $this->querySpec->expects(self::once())
            ->method('build')
            ->with('shoes', 1)
            ->willReturn([
                'collection' => 'alias_1',
                'params' => ['q' => 'shoes', 'per_page' => 5],
                'embedded' => ['per_page' => 5],
            ]);

        $this->client->expects(self::once())
            ->method('request')
            ->with('GET', '/collections/alias_1/documents/search', null, ['q' => 'shoes', 'per_page' => 5], 1, 1)
            ->willReturn(['found' => 2, 'hits' => [
                ['document' => ['name' => 'Red Shoe']],
                ['document' => ['name' => 'Blue Shoe']],
                ['not_a_document' => true],
            ]]);

        $mapped = [['title' => 'Red Shoe'], ['title' => 'Blue Shoe']];
        $this->resultMapper->expects(self::once())
            ->method('map')
            ->with([['name' => 'Red Shoe'], ['name' => 'Blue Shoe']], 1)
            ->willReturn($mapped);

        $this->controller->execute();

        self::assertSame(['items' => $mapped, 'found' => 2], $this->captured);
    }

    public function testDisabledModuleReturnsEmptyEnvelope(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->logger->expects(self::never())->method('error');
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->rateGuard->method('allow')->willReturn(true);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testStoreScopedSettingsAreReadForTheCurrentStore(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(3));
        $this->config->expects(self::once())->method('isEnabled')->with(3)->willReturn(true);
        $this->config->expects(self::once())->method('getMinQueryLength')->with(3)->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('ab');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTermBelowMinLengthReturnsEmptyEnvelope(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->logger->expects(self::never())->method('error');
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->rateGuard->method('allow')->willReturn(true);
        $this->request->method('getParam')->with('q', '')->willReturn(' ab ');
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTermAboveMaxLengthReturnsEmptyEnvelopeWithoutUpstreamCall(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->logger->expects(self::never())->method('error');
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->rateGuard->method('allow')->willReturn(true);
        // A multi-hundred-character term must be dropped before any Typesense call.
        $this->request->method('getParam')->with('q', '')->willReturn(str_repeat('a', 256));
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTermOfMaxLengthInMultibyteCharactersIsQueried(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->rateGuard->method('allow')->willReturn(true);
        // 255 characters but 510 bytes: the cap counts characters.
        $this->request->method('getParam')->with('q', '')->willReturn(str_repeat('ä', 255));
        $this->querySpec->expects(self::once())->method('build')->willReturn(null);

        $this->controller->execute();
    }

    public function testMappingFailureDegradesToEmptyAndLogs(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->rateGuard->method('allow')->willReturn(true);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->querySpec->method('build')->willReturn(['collection' => 'a', 'params' => [], 'embedded' => []]);
        $this->client->method('request')->willReturn(['found' => 1, 'hits' => [['document' => ['name' => 'x']]]]);
        $this->resultMapper->method('map')->willThrowException(new \RuntimeException('no store'));
        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTrippedRateGuardReturnsEmptyEnvelope(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->clientIp->method('get')->willReturn('203.0.113.7');
        $this->rateGuard->expects(self::once())->method('allow')->with('203.0.113.7')->willReturn(false);
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testRateGuardIsKeyedOnTheClientIp(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->clientIp->expects(self::once())->method('get')->willReturn('198.51.100.42');
        $this->rateGuard->expects(self::once())->method('allow')->with('198.51.100.42')->willReturn(false);

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testNoSearchableFieldsReturnsEmptyEnvelope(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->clientIp->method('get')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->querySpec->method('build')->willReturn(null);
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTypesenseErrorDegradesToEmptyAndLogs(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->clientIp->method('get')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->querySpec->method('build')->willReturn(['collection' => 'alias_1', 'params' => [], 'embedded' => []]);
        $this->client->method('request')->willThrowException(new \RuntimeException('boom'));
        $this->resultMapper->expects(self::never())->method('map');
        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testUnresolvableStoreDegradesToEmptyAndLogs(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->clientIp->method('get')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));
        $this->querySpec->expects(self::never())->method('build');
        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    /**
     * Wire a valid term through the guards up to the Typesense call.
     */
    private function validRequest(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->clientIp->method('get')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->querySpec->method('build')->willReturn(['collection' => 'alias_1', 'params' => [], 'embedded' => []]);
    }

    public function testDownFlagAnswersUnavailableWithoutQuerying(): void
    {
        $this->validRequest();
        $this->availability->method('isDown')->willReturn(true);
        $this->client->expects(self::never())->method('request');
        $this->availability->expects(self::never())->method('markDown');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0, 'unavailable' => true], $this->captured);
    }

    public function testTransportErrorMarksDownAndAnswersUnavailable(): void
    {
        $this->validRequest();
        $this->availability->method('isDown')->willReturn(false);
        $this->client->method('request')->willThrowException(new TransportException('timeout'));
        $this->availability->expects(self::once())->method('markDown');
        $this->resultMapper->expects(self::never())->method('map');
        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0, 'unavailable' => true], $this->captured);
    }

    public function testOtherErrorDoesNotMarkDown(): void
    {
        $this->validRequest();
        $this->availability->method('isDown')->willReturn(false);
        $this->client->method('request')->willThrowException(new \RuntimeException('bad request'));
        $this->availability->expects(self::never())->method('markDown');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testRequestUsesOneSecondTimeoutAndSingleAttempt(): void
    {
        $this->validRequest();
        $this->availability->method('isDown')->willReturn(false);
        $this->client->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (...$args): array {
                self::assertSame(1, $args[4]);
                self::assertSame(1, $args[5]);

                return ['found' => 0, 'hits' => []];
            });
        $this->resultMapper->method('map')->willReturn([]);

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testConstructorTakesNoAdminKeyClient(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string)$p->getType(),
            (new \ReflectionMethod(Search::class, '__construct'))->getParameters()
        );

        self::assertNotContains(TypesenseClient::class, $types);
        self::assertContains(ProxyClientProvider::class, $types);
    }

    public function testClientIsBuiltForTheStoreWithTheEmbeddedParams(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->storeManager->method('getStore')->willReturn($this->store(2));
        $embedded = ['include_fields' => 'name', 'filter_by' => 'status:=1', 'per_page' => 5];
        $this->querySpec->method('build')->willReturn(
            ['collection' => 'alias_2', 'params' => ['q' => 'shoes'] + $embedded, 'embedded' => $embedded]
        );
        $clientProvider = $this->createMock(ProxyClientProvider::class);
        $clientProvider->expects(self::once())->method('get')->with(2, $embedded)->willReturn($this->client);
        $this->client->method('request')->willReturn(['found' => 0, 'hits' => []]);
        $this->resultMapper->method('map')->willReturn([]);

        $this->controllerWith($clientProvider)->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testMissingProxyKeyAnswersUnavailableAndWarnsOnce(): void
    {
        $this->validRequest();
        $this->availability->method('isDown')->willReturn(false);
        $clientProvider = $this->createMock(ProxyClientProvider::class);
        $clientProvider->method('get')->willReturn(null);
        $this->client->expects(self::never())->method('request');
        $this->availability->expects(self::never())->method('markDown');
        $this->logger->expects(self::once())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $this->controllerWith($clientProvider)->execute();

        self::assertSame(['items' => [], 'found' => 0, 'unavailable' => true], $this->captured);
    }

    /**
     * The controller wired with a specific client provider.
     */
    private function controllerWith(ProxyClientProvider $clientProvider): Search
    {
        return new Search(
            $this->config,
            $this->querySpec,
            $this->resultMapper,
            $clientProvider,
            $this->storeManager,
            $this->request,
            $this->resultJsonFactory,
            $this->clientIp,
            $this->rateGuard,
            $this->logger,
            $this->availability
        );
    }
}
