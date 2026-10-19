<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Block\Adminhtml\System\Config;

use DmLab\TypesenseInstantSearch\Block\Adminhtml\System\Config\RegenerateButton;
use DmLab\TypesenseInstantSearch\Test\Unit\Doubles\MarkingPhraseRenderer;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\AbstractBlock;
use PHPUnit\Framework\TestCase;

/**
 * The button markup carries the regenerate controller URL and the CSRF form key in data attributes,
 * both escaped for an HTML attribute, and wires the static handler via `data-mage-init` — no inline
 * `<script>`. Instantiated without the heavy block constructor; only the string build matters.
 */
class RegenerateButtonTest extends TestCase
{
    protected function setUp(): void
    {
        MarkingPhraseRenderer::install();
    }

    protected function tearDown(): void
    {
        MarkingPhraseRenderer::uninstall();
    }

    public function testButtonHtmlCarriesTheEscapedUrlAndFormKeyAndNoInlineScript(): void
    {
        $html = $this->block()->buildHtml('https://admin/typesense/regenerate', 'the-form-key');

        self::assertStringContainsString('escaped(https://admin/typesense/regenerate)', $html);
        self::assertStringContainsString('escaped(the-form-key)', $html);
        // The handler is a static component bound via data-mage-init, not an inline script.
        self::assertStringContainsString('data-mage-init', $html);
        self::assertStringContainsString('DmLab_TypesenseInstantSearch/js/regenerate', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testLabelIsTranslatedAndEscaped(): void
    {
        $html = $this->block()->buildHtml('u', 'k');

        self::assertStringContainsString('>html(tr(Regenerate key))</button>', $html);
    }

    private function block(): RegenerateButton
    {
        $escaper = $this->createStub(Escaper::class);
        // Prove the values are routed through the escaper, and echo them back so we can assert them.
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn (string $value): string => 'escaped(' . $value . ')'
        );
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => 'html(' . $value . ')'
        );

        $block = (new \ReflectionClass(RegenerateButton::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AbstractBlock::class, '_escaper'))->setValue($block, $escaper);

        return $block;
    }
}
