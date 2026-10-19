<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Controller\Adminhtml\System\Config;

use DmLab\TypesenseInstantSearch\Controller\Adminhtml\System\Config\RegenerateKey;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Test\Unit\Doubles\MarkingPhraseRenderer;
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
        MarkingPhraseRenderer::install();
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

    protected function tearDown(): void
    {
        MarkingPhraseRenderer::uninstall();
    }

    public function testUsesTheTypesenseConfigSectionAclResource(): void
    {
        self::assertSame('DmLab_TypesenseIndexer::config', RegenerateKey::ADMIN_RESOURCE);
    }

    public function testRegeneratesAllStoresAndReportsTranslatedSuccess(): void
    {
        $this->provisioner->expects(self::once())->method('regenerate')->with(null);

        $this->result->expects(self::once())
            ->method('setData')
            ->with([
                'success' => true,
                'message' => 'tr(Search-only keys regenerated for all store views.)',
            ]);

        self::assertSame($this->result, $this->controller->execute());
    }

    public function testReportsTranslatedFailureWithoutThrowingWhenProvisioningFails(): void
    {
        $this->provisioner->method('regenerate')
            ->willThrowException(new \RuntimeException('admin key missing'));
        $this->logger->expects(self::once())->method('error');

        $this->result->expects(self::once())
            ->method('setData')
            ->with([
                'success' => false,
                'message' => 'tr(Could not regenerate the search-only keys: admin key missing '
                    . 'Check the Typesense admin API key.)',
            ]);

        self::assertSame($this->result, $this->controller->execute());
    }

    private function set(string $property, object $value): void
    {
        (new \ReflectionProperty(RegenerateKey::class, $property))->setValue($this->controller, $value);
    }
}
