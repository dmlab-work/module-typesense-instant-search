<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Price;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\DataObjectFactory;

/**
 * Applies the store's catalog price display tax rule to an indexed base price, without loading the product.
 */
class TaxPriceCalculator
{
    /**
     * @param CatalogHelper $catalogHelper Magento's display-price tax calculation
     * @param DataObjectFactory $dataObjectFactory builds the product stand-in the helper reads
     */
    public function __construct(
        private readonly CatalogHelper $catalogHelper,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    /**
     * The display price for an amount of the given product tax class, per the store's tax display type.
     *
     * @param float $amount base-currency price as indexed
     * @param int $taxClassId product tax class id (0 = none)
     * @param int $storeId
     * @param bool $round false keeps full precision (for factors applied later)
     */
    public function apply(float $amount, int $taxClassId, int $storeId, bool $round = true): float
    {
        if ($amount === 0.0) {
            return 0.0;
        }

        $product = $this->dataObjectFactory->create([
            'data' => ['tax_class_id' => $taxClassId, 'sku' => '', 'short_description' => ''],
        ]);

        return (float)$this->catalogHelper->getTaxPrice(
            $product,
            $amount,
            null,
            null,
            null,
            null,
            $storeId,
            null,
            $round
        );
    }
}
