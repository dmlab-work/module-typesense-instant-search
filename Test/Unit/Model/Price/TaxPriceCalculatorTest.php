<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Price;

use DmLab\TypesenseInstantSearch\Model\Price\TaxPriceCalculator;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaxPriceCalculatorTest extends TestCase
{
    /** @var CatalogHelper&MockObject */
    private $catalogHelper;

    /** @var DataObjectFactory&MockObject */
    private $dataObjectFactory;

    private TaxPriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->catalogHelper = $this->createMock(CatalogHelper::class);
        $this->dataObjectFactory = $this->createMock(DataObjectFactory::class);
        $this->calculator = new TaxPriceCalculator($this->catalogHelper, $this->dataObjectFactory);
    }

    public function testPassesTaxClassAndStoreAndReturnsHelperResult(): void
    {
        $this->dataObjectFactory->expects(self::once())
            ->method('create')
            ->willReturnCallback(static fn (array $args): DataObject => new DataObject($args['data']));
        $this->catalogHelper->expects(self::once())
            ->method('getTaxPrice')
            ->with(
                self::callback(static fn (DataObject $product): bool => $product->getTaxClassId() === 2
                    && $product->getSku() === ''
                    && $product->getShortDescription() === ''),
                100.0,
                null,
                null,
                null,
                null,
                3,
                null,
                true
            )
            ->willReturn(121.0);

        self::assertSame(121.0, $this->calculator->apply(100.0, 2, 3));
    }

    public function testUnroundedModeAsksTheHelperNotToRound(): void
    {
        $this->dataObjectFactory->expects(self::once())->method('create')->willReturn(new DataObject());
        $this->catalogHelper->expects(self::once())
            ->method('getTaxPrice')
            ->with(self::anything(), 100.0, null, null, null, null, 3, null, false)
            ->willReturn(84.033613);

        self::assertSame(84.033613, $this->calculator->apply(100.0, 2, 3, false));
    }

    public function testZeroAmountPassesThroughWithoutCalculation(): void
    {
        $this->dataObjectFactory->expects(self::never())->method('create');
        $this->catalogHelper->expects(self::never())->method('getTaxPrice');

        self::assertSame(0.0, $this->calculator->apply(0.0, 2, 1));
    }
}
