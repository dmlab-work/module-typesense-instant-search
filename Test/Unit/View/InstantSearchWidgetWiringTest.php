<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Static assertions on the storefront wiring: the RequireJS map override, the
 * layout/template that emits the view model's config, and the widget contract.
 * JS runtime behaviour is verified manually (see the plan's Post-Completion).
 */
class InstantSearchWidgetWiringTest extends TestCase
{
    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testRequireJsMapsQuickSearchToInstantSearch(): void
    {
        $js = (string)file_get_contents(self::moduleRoot() . '/view/frontend/requirejs-config.js');

        // The stock `quickSearch` alias is remapped in place to our widget — the swap
        // that replaces Magento's autocomplete without a template or layout override.
        self::assertMatchesRegularExpression(
            '/quickSearch\s*:\s*[\'"]DmLab_TypesenseInstantSearch\/js\/instant-search[\'"]/',
            $js
        );
    }

    public function testWidgetExtendsTheStockFormMiniWidget(): void
    {
        $js = (string)file_get_contents(
            self::moduleRoot() . '/view/frontend/web/js/instant-search.js'
        );

        // Extend, don't reimplement: keyboard nav, submit and native fallback are inherited.
        self::assertStringContainsString('Magento_Search/js/form-mini', $js);
        self::assertMatchesRegularExpression(
            '/\$\.widget\(\s*[\'"]mage\.quickSearch[\'"]\s*,\s*\$\.mage\.quickSearch/',
            $js
        );
    }

    public function testWidgetQueriesTypesenseSearchEndpointWithTheDerivedKey(): void
    {
        $js = (string)file_get_contents(
            self::moduleRoot() . '/view/frontend/web/js/instant-search.js'
        );

        // Direct browser → Typesense search, authenticated with the derived scoped key.
        self::assertStringContainsString('/documents/search', $js);
        self::assertStringContainsString('X-TYPESENSE-API-KEY', $js);
    }

    public function testWidgetRendersTheConfiguredDisplayFields(): void
    {
        $js = (string)file_get_contents(
            self::moduleRoot() . '/view/frontend/web/js/instant-search.js'
        );

        // The admin display-field toggles (title/image/price/sku) must reach the row render,
        // not be dead config: the widget reads `displayFields` and emits a cell per enabled field.
        self::assertStringContainsString('displayFields', $js);
        self::assertStringContainsString('qs-option-image', $js);
        self::assertStringContainsString('qs-option-price', $js);
        self::assertStringContainsString('qs-option-sku', $js);
    }

    public function testWidgetDegradesToNativeWhenConfigAbsent(): void
    {
        $js = (string)file_get_contents(
            self::moduleRoot() . '/view/frontend/web/js/instant-search.js'
        );

        // Fail closed: without a usable config every override delegates to the native widget.
        self::assertStringContainsString('_super()', $js);
    }

    public function testLayoutWiresTheViewModelIntoTheConfigBlock(): void
    {
        $xml = simplexml_load_file(
            self::moduleRoot() . '/view/frontend/layout/default.xml'
        );
        self::assertNotFalse($xml);

        $block = $xml->xpath(
            '//block[@name="dmlab.typesense.instant_search.config"]'
        );
        self::assertNotEmpty($block, 'config block must be declared');

        self::assertSame(
            'DmLab_TypesenseInstantSearch::config.phtml',
            (string)$block[0]['template']
        );

        $viewModel = $xml->xpath(
            '//block[@name="dmlab.typesense.instant_search.config"]'
            . '/arguments/argument[@name="view_model"]'
        );
        self::assertNotEmpty($viewModel, 'view model must be wired as a block argument');
        $xsiType = $viewModel[0]->attributes('xsi', true)['type'];
        self::assertSame('object', (string)$xsiType);
        self::assertSame(
            'DmLab\\TypesenseInstantSearch\\ViewModel\\InstantSearchConfig',
            trim((string)$viewModel[0])
        );
    }

    public function testTemplateEmitsTheViewModelJsonConfig(): void
    {
        $phtml = (string)file_get_contents(
            self::moduleRoot() . '/view/frontend/templates/config.phtml'
        );

        // The template reads the wired view model and prints its JSON for the widget to read.
        self::assertStringContainsString('getViewModel()', $phtml);
        self::assertStringContainsString('getJsonConfig()', $phtml);
        self::assertStringContainsString('dmlab-typesense-instant-search-config', $phtml);
    }
}
