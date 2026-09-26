<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Observer;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use DmLab\TypesenseInstantSearch\Observer\ProvisionSearchKey;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ProvisionSearchKeyTest extends TestCase
{
    /** @var SearchKeyProvisioner&MockObject */
    private $provisioner;

    private ProvisionSearchKey $observer;

    protected function setUp(): void
    {
        $this->provisioner = $this->createMock(SearchKeyProvisioner::class);
        $this->observer = new ProvisionSearchKey($this->provisioner);
    }

    public function testDelegatesToTheProvisionerOnSave(): void
    {
        $this->provisioner->expects(self::once())->method('ensure');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testWrapsProvisionerFailureAsASaveTimeError(): void
    {
        $this->provisioner->method('ensure')
            ->willThrowException(new \RuntimeException('No Typesense admin API key configured.'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No Typesense admin API key configured.');

        $this->observer->execute($this->createMock(Observer::class));
    }
}
