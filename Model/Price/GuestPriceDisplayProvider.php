<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Price;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;

/**
 * Currency rate and per-tax-class display factors the direct-mode widget applies to the public `price`.
 *
 * Rendered only on the NOT LOGGED IN page variant, so the tax factors use the store's default tax address.
 */
class GuestPriceDisplayProvider
{
    /** Reference amount the factors are measured on (unrounded, so any rate stays exact). */
    private const REFERENCE_AMOUNT = 100.0;

    /**
     * @param TaxPriceCalculator $taxPriceCalculator
     * @param ProductTaxClassSource $taxClassSource product tax classes
     */
    public function __construct(
        private readonly TaxPriceCalculator $taxPriceCalculator,
        private readonly ProductTaxClassSource $taxClassSource
    ) {
    }

    /**
     * The base → current currency rate and the display-price factor per product tax class id.
     *
     * @param StoreInterface $store
     * @return array{rate:float,taxFactors:array<int,float>}
     */
    public function get(StoreInterface $store): array
    {
        $storeId = (int)$store->getId();

        $classIds = [0];
        // `getAllOptions(false)` returns null when no product tax class exists.
        foreach ($this->taxClassSource->getAllOptions(false) ?? [] as $option) {
            $classIds[] = (int)$option['value'];
        }

        $taxFactors = [];
        foreach (array_unique($classIds) as $classId) {
            $taxFactors[$classId] = $this->taxPriceCalculator->apply(self::REFERENCE_AMOUNT, $classId, $storeId, false)
                / self::REFERENCE_AMOUNT;
        }

        return ['rate' => $this->rate($store), 'taxFactors' => $taxFactors];
    }

    /**
     * The base → current currency rate, or 1.0 when the currency is the base one or has no rate.
     *
     * @param StoreInterface $store
     */
    private function rate(StoreInterface $store): float
    {
        if (!$store instanceof Store) {
            return 1.0;
        }

        $current = (string)$store->getCurrentCurrencyCode();
        if ($current === '' || $current === (string)$store->getBaseCurrencyCode()) {
            return 1.0;
        }

        $rate = (float)$store->getBaseCurrency()->getRate($current);

        return $rate > 0.0 ? $rate : 1.0;
    }
}
