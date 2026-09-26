<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Controller\Adminhtml\System\Config;

use DmLab\TypesenseInstantSearch\Controller\Adminhtml\System\Config\RegenerateKey;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class RegenerateKeyTest extends TestCase
{
    /** @var JsonFactory&MockObject */
    private $resultJsonFactory;

    /** @var Json&MockObject */
    private $result;

    /** @var SearchKeyProvisioner&MockObject */
    private $provisioner;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private RegenerateKey $controller;

    protected function setUp(): void
    {
        $this->resultJsonFactory = $this->createMock(JsonFactory::class);
        $this->result = $this->createMock(Json::class);
        $this->result->method('setData')->willReturnSelf();
        $this->resultJsonFactory->method('create')->willReturn($this->result);
        $this->provisioner = $this->createMock(SearchKeyProvisioner::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = (new \ReflectionClass(RegenerateKey::class))->newInstanceWithoutConstructor();
        $this->set('resultJsonFactory', $this->resultJsonFactory);
        $this->set('provisioner', $this->provisioner);
        $this->set('logger', $this->logger);
    }

    public function testReProvisionsAndReportsSuccess(): void
    {
        $this->provisioner->expects(self::once())->method('regenerate');

        $this->result->expects(self::once())
            ->method('setData')
            ->with(self::callback(static fn (array $data): bool =>
                $data['success'] === true && str_contains($data['message'], 'regenerated')));

        self::assertSame($this->result, $this->controller->execute());
    }

    public function testReportsFailureWithoutThrowingWhenProvisioningFails(): void
    {
        $this->provisioner->method('regenerate')
            ->willThrowException(new \RuntimeException('admin key missing'));
        $this->logger->expects(self::once())->method('error');

        $this->result->expects(self::once())
            ->method('setData')
            ->with(self::callback(static fn (array $data): bool =>
                $data['success'] === false && str_contains($data['message'], 'admin key missing')));

        self::assertSame($this->result, $this->controller->execute());
    }

    private function set(string $property, object $value): void
    {
        (new \ReflectionProperty(RegenerateKey::class, $property))->setValue($this->controller, $value);
    }
}
