<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\Model;

use MageDevGroup\TypesenseInstantSearch\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testIsEnabledReadsTheMasterSwitch(): void
    {
        $this->scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_ENABLED)
            ->willReturn(true);

        self::assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledIsFalseWhenTheSwitchIsOff(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with(Config::XML_PATH_ENABLED)
            ->willReturn(false);

        self::assertFalse($this->config->isEnabled());
    }

    public function testGetModeDefaultsToProxyWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_MODE)
            ->willReturn(null);

        self::assertSame('proxy', $this->config->getMode());
        self::assertFalse($this->config->isDirectMode());
    }

    public function testGetModeDefaultsToProxyForAnUnrecognisedValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_MODE)
            ->willReturn('nonsense');

        self::assertSame('proxy', $this->config->getMode());
        self::assertFalse($this->config->isDirectMode());
    }

    public function testGetModeReturnsDirectWhenConfigured(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_MODE)
            ->willReturn('direct');

        self::assertSame('direct', $this->config->getMode());
        self::assertTrue($this->config->isDirectMode());
    }

    public function testProxyModeNeverRequiresAPublicHost(): void
    {
        // In proxy mode the reader resolves without any public host — Typesense stays private,
        // so the host is never consulted to build the widget contract.
        $this->scopeConfig->expects(self::atLeastOnce())
            ->method('getValue')
            ->willReturn(null);

        self::assertFalse($this->config->isDirectMode());
        self::assertNull($this->config->getPublicHost());
    }

    public function testGetPublicHostReadsItsOwnPathNotTheServerHost(): void
    {
        // The public host must come from this module's path, never core's server host —
        // the browser may reach Typesense at a different address than PHP does.
        self::assertNotSame(
            'catalog/search/typesense_server_hostname',
            Config::XML_PATH_PUBLIC_HOST
        );

        $this->scopeConfig->expects(self::once())
            ->method('getValue')
            ->with(Config::XML_PATH_PUBLIC_HOST)
            ->willReturn('search.example.com');

        self::assertSame('search.example.com', $this->config->getPublicHost());
    }

    public function testGetPublicHostIsNullWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_PUBLIC_HOST)
            ->willReturn(null);

        self::assertNull($this->config->getPublicHost());
    }

    public function testGetPublicHostRejectsAValueCarryingAScheme(): void
    {
        // A scheme in the host would build a double-scheme URL downstream, so it reads as unset.
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_PUBLIC_HOST)
            ->willReturn('https://search.example.com');

        self::assertNull($this->config->getPublicHost());
    }

    public function testGetMinQueryLengthReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_MIN_QUERY_LENGTH)
            ->willReturn('4');

        self::assertSame(4, $this->config->getMinQueryLength());
    }

    public function testGetMinQueryLengthFallsBackToTheDefault(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_MIN_QUERY_LENGTH)
            ->willReturn(null);

        self::assertSame(3, $this->config->getMinQueryLength());
    }

    public function testGetMinQueryLengthTreatsZeroAsAMisconfiguration(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_MIN_QUERY_LENGTH)
            ->willReturn('0');

        self::assertSame(3, $this->config->getMinQueryLength());
    }

    public function testGetResultLimitReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_RESULT_LIMIT)
            ->willReturn('12');

        self::assertSame(12, $this->config->getResultLimit());
    }

    public function testGetResultLimitFallsBackToTheDefault(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_RESULT_LIMIT)
            ->willReturn('');

        self::assertSame(5, $this->config->getResultLimit());
    }

    public function testGetDebounceMsReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_DEBOUNCE_MS)
            ->willReturn('350');

        self::assertSame(350, $this->config->getDebounceMs());
    }

    public function testGetDebounceMsFallsBackToTheDefault(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_DEBOUNCE_MS)
            ->willReturn(null);

        self::assertSame(200, $this->config->getDebounceMs());
    }

    public function testGetDebounceMsAllowsZero(): void
    {
        // Unlike the length/limit, zero debounce is a legitimate "query immediately" choice.
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_DEBOUNCE_MS)
            ->willReturn('0');

        self::assertSame(0, $this->config->getDebounceMs());
    }

    public function testGetDisplayFieldsReflectsEachToggle(): void
    {
        $flags = [
            Config::XML_PATH_DISPLAY_TITLE => true,
            Config::XML_PATH_DISPLAY_IMAGE => true,
            Config::XML_PATH_DISPLAY_PRICE => false,
            Config::XML_PATH_DISPLAY_SKU => true,
        ];
        $this->scopeConfig->expects(self::exactly(4))
            ->method('isSetFlag')
            ->willReturnCallback(static fn(string $path): bool => $flags[$path] ?? false);

        self::assertSame(
            ['title' => true, 'image' => true, 'price' => false, 'sku' => true],
            $this->config->getDisplayFields()
        );
    }
}
