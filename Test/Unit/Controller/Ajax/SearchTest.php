<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Controller\Ajax;

use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseInstantSearch\Controller\Ajax\Search;
use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\RateGuard;
use DmLab\TypesenseInstantSearch\Model\Search\QuerySpec;
use DmLab\TypesenseInstantSearch\Model\Search\ResultMapper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
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

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var RequestInterface&MockObject */
    private $request;

    /** @var JsonFactory&MockObject */
    private $resultJsonFactory;

    /** @var RemoteAddress&MockObject */
    private $remoteAddress;

    /** @var RateGuard&MockObject */
    private $rateGuard;

    /** @var LoggerInterface&MockObject */
    private $logger;

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
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->resultJsonFactory = $this->createMock(JsonFactory::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->rateGuard = $this->createMock(RateGuard::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->captured = $data;

            return $json;
        });
        $this->resultJsonFactory->method('create')->willReturn($json);

        $this->controller = new Search(
            $this->config,
            $this->querySpec,
            $this->resultMapper,
            $this->client,
            $this->storeManager,
            $this->request,
            $this->resultJsonFactory,
            $this->remoteAddress,
            $this->rateGuard,
            $this->logger
        );
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
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willReturn($this->store(1));

        $this->querySpec->expects(self::once())
            ->method('build')
            ->with('shoes', 1)
            ->willReturn(['collection' => 'alias_1', 'params' => ['q' => 'shoes', 'per_page' => 5]]);

        $this->client->expects(self::once())
            ->method('request')
            ->with('GET', '/collections/alias_1/documents/search', null, ['q' => 'shoes', 'per_page' => 5])
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
        $this->config->method('isEnabled')->willReturn(false);
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTermBelowMinLengthReturnsEmptyEnvelope(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn(' ab ');
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTermAboveMaxLengthReturnsEmptyEnvelopeWithoutUpstreamCall(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        // A multi-hundred-character term must be dropped before any Typesense call.
        $this->request->method('getParam')->with('q', '')->willReturn(str_repeat('a', 256));
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testTrippedRateGuardReturnsEmptyEnvelope(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');
        $this->rateGuard->expects(self::once())->method('allow')->with('203.0.113.7')->willReturn(false);
        $this->querySpec->expects(self::never())->method('build');
        $this->client->expects(self::never())->method('request');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }

    public function testNoSearchableFieldsReturnsEmptyEnvelope(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMinQueryLength')->willReturn(3);
        $this->request->method('getParam')->with('q', '')->willReturn('shoes');
        $this->rateGuard->method('allow')->willReturn(true);
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');
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
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willReturn($this->store(1));
        $this->querySpec->method('build')->willReturn(['collection' => 'alias_1', 'params' => []]);
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
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');
        $this->storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));
        $this->querySpec->expects(self::never())->method('build');
        $this->logger->expects(self::once())->method('error');

        $this->controller->execute();

        self::assertSame(['items' => [], 'found' => 0], $this->captured);
    }
}
