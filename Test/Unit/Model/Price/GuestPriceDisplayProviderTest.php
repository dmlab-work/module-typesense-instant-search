<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Price;

use DmLab\TypesenseInstantSearch\Model\Price\GuestPriceDisplayProvider;
use DmLab\TypesenseInstantSearch\Model\Price\TaxPriceCalculator;
use Magento\Directory\Model\Currency;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class GuestPriceDisplayProviderTest extends TestCase
{
    /** @var TaxPriceCalculator&MockObject */
    private $taxPriceCalculator;

    /** @var ProductTaxClassSource&MockObject */
    private $taxClassSource;

    private GuestPriceDisplayProvider $provider;

    protected function setUp(): void
    {
        $this->taxPriceCalculator = $this->createMock(TaxPriceCalculator::class);
        $this->taxClassSource = $this->createMock(ProductTaxClassSource::class);
        $this->taxClassSource->method('getAllOptions')->with(false)->willReturn([]);
        $this->provider = new GuestPriceDisplayProvider($this->taxPriceCalculator, $this->taxClassSource);
    }

    public function testFactorPerProductTaxClassPlusClassZero(): void
    {
        $taxClassSource = $this->createMock(ProductTaxClassSource::class);
        $taxClassSource->method('getAllOptions')->with(false)->willReturn([
            ['value' => '2', 'label' => 'Taxable Goods'],
            ['value' => '5', 'label' => 'Reduced'],
        ]);
        $calls = [];
        $this->taxPriceCalculator->method('apply')->willReturnCallback(
            static function (float $amount, int $classId, int $storeId, bool $round) use (&$calls): float {
                $calls[] = [$amount, $classId, $storeId, $round];

                return match ($classId) {
                    2 => 108.25,
                    5 => 105.0,
                    default => $amount,
                };
            }
        );

        $result = (new GuestPriceDisplayProvider($this->taxPriceCalculator, $taxClassSource))
            ->get($this->store('USD', 'USD'));

        self::assertSame([0 => 1.0, 2 => 1.0825, 5 => 1.05], $result['taxFactors']);
        self::assertSame([[100.0, 0, 3, false], [100.0, 2, 3, false], [100.0, 5, 3, false]], $calls);
    }

    public function testNoProductTaxClassesYieldsClassZeroOnly(): void
    {
        $taxClassSource = $this->createMock(ProductTaxClassSource::class);
        $taxClassSource->method('getAllOptions')->with(false)->willReturn(null);
        $this->taxPriceCalculator->method('apply')->willReturnArgument(0);

        $result = (new GuestPriceDisplayProvider($this->taxPriceCalculator, $taxClassSource))
            ->get($this->store('USD', 'USD'));

        self::assertSame([0 => 1.0], $result['taxFactors']);
    }

    public function testRateIsTheBaseToCurrentCurrencyRate(): void
    {
        $this->taxPriceCalculator->method('apply')->willReturnArgument(0);

        self::assertSame(0.92, $this->provider->get($this->store('USD', 'EUR', 0.92))['rate']);
    }

    /**
     * @return array<string,array{0:string,1:string,2:float|int|false|null}>
     */
    public static function rateFallbackProvider(): array
    {
        return [
            'same currency' => ['USD', 'USD', 0.5],
            'no rate' => ['USD', 'EUR', false],
            'null rate' => ['USD', 'EUR', null],
            'zero rate' => ['USD', 'EUR', 0],
        ];
    }

    #[DataProvider('rateFallbackProvider')]
    public function testRateFallsBackToOne(string $base, string $current, float|int|false|null $rate): void
    {
        $this->taxPriceCalculator->method('apply')->willReturnArgument(0);

        self::assertSame(1.0, $this->provider->get($this->store($base, $current, $rate))['rate']);
    }

    public function testNonStoreModelGetsRateOne(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->taxPriceCalculator->method('apply')->willReturnArgument(0);

        self::assertSame(['rate' => 1.0, 'taxFactors' => [0 => 1.0]], $this->provider->get($store));
    }

    private function store(string $base, string $current, float|int|false|null $rate = 1.0): Store
    {
        $currency = $this->createMock(Currency::class);
        $currency->method('getRate')->with($current)->willReturn($rate);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getBaseCurrencyCode')->willReturn($base);
        $store->method('getCurrentCurrencyCode')->willReturn($current);
        $store->method('getBaseCurrency')->willReturn($currency);

        return $store;
    }
}
