<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model\Key;

use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyStatus;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use DmLab\TypesenseInstantSearch\Test\Unit\Doubles\MarkingPhraseRenderer;
use Magento\Framework\Escaper;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SearchKeyStatusTest extends TestCase
{
    protected function setUp(): void
    {
        MarkingPhraseRenderer::install();
    }

    protected function tearDown(): void
    {
        MarkingPhraseRenderer::uninstall();
    }

    public function testRendersOneRowPerStoreWithEachRoleStatus(): void
    {
        $keyProvider = $this->createStub(SearchKeyProvider::class);
        $keyProvider->method('getParentKey')->willReturnMap([
            [1, 'public', 'p1'],
            [1, 'proxy', 'x1'],
            [2, 'public', null],
            [2, 'proxy', 'x2'],
        ]);

        $html = $this->keyStatus($keyProvider, [1 => 'default', 2 => 'fr'])->getHtml();

        self::assertSame(2, substr_count($html, '<tbody><tr>') + substr_count($html, '</tr><tr>'));
        self::assertMatchesRegularExpression(
            '#<tr><td>esc\(default\)</td><td[^>]*>esc\(tr\(Provisioned\)\)</td><td[^>]*>esc\(tr\(Provisioned\)\)</td></tr>#',
            $html
        );
        self::assertMatchesRegularExpression(
            '#<tr><td>esc\(fr\)</td><td[^>]*>esc\(tr\(Missing\)\)</td><td[^>]*>esc\(tr\(Provisioned\)\)</td></tr>#',
            $html
        );
        self::assertStringContainsString('esc(tr(Public key (direct mode)))', $html);
        self::assertStringContainsString('esc(tr(Proxy key))', $html);
        self::assertStringContainsString('class="note"', $html);
    }

    public function testOmitsTheNoteWhenEveryKeyIsProvisioned(): void
    {
        $keyProvider = $this->createStub(SearchKeyProvider::class);
        $keyProvider->method('getParentKey')->willReturn('k');

        $html = $this->keyStatus($keyProvider, [1 => 'default'])->getHtml();

        self::assertStringNotContainsString('Missing', $html);
        self::assertStringNotContainsString('class="note"', $html);
    }

    public function testProxyModeShowsOnlyTheProxyKey(): void
    {
        $keyProvider = $this->createStub(SearchKeyProvider::class);
        $keyProvider->method('getParentKey')->willReturnMap([[1, 'public', null], [1, 'proxy', 'x1']]);

        $html = $this->keyStatus($keyProvider, [1 => 'default'], false, false)->getHtml();

        self::assertStringNotContainsString('Public key', $html);
        self::assertStringNotContainsString('Missing', $html);
        self::assertStringContainsString('esc(tr(Proxy key))', $html);
    }

    public function testEscapesStoreCodes(): void
    {
        $keyProvider = $this->createStub(SearchKeyProvider::class);
        $keyProvider->method('getParentKey')->willReturn('k');

        $html = $this->keyStatus($keyProvider, [1 => '<b>x</b>'], true)->getHtml();

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    /**
     * @param array<int,string> $codes store id => code
     */
    private function keyStatus(
        SearchKeyProvider $keyProvider,
        array $codes,
        bool $realEscaper = false,
        bool $directMode = true
    ): SearchKeyStatus {
        $stores = [];
        foreach ($codes as $id => $code) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            $realEscaper
                ? static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
                : static fn (string $value): string => 'esc(' . $value . ')'
        );

        $config = $this->createStub(Config::class);
        $config->method('isDirectMode')->willReturn($directMode);

        return new SearchKeyStatus($keyProvider, $storeManager, $escaper, $config);
    }
}
