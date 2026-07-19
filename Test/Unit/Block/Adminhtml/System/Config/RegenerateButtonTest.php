<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\Block\Adminhtml\System\Config;

use MageDevGroup\TypesenseInstantSearch\Block\Adminhtml\System\Config\RegenerateButton;
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
    public function testButtonHtmlCarriesTheEscapedUrlAndFormKeyAndNoInlineScript(): void
    {
        $escaper = $this->createStub(Escaper::class);
        // Prove the values are routed through the escaper, and echo them back so we can assert them.
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn (string $value): string => 'escaped(' . $value . ')'
        );

        $block = (new \ReflectionClass(RegenerateButton::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AbstractBlock::class, '_escaper'))->setValue($block, $escaper);

        $html = $block->buildHtml('https://admin/typesense/regenerate', 'the-form-key');

        self::assertStringContainsString('escaped(https://admin/typesense/regenerate)', $html);
        self::assertStringContainsString('escaped(the-form-key)', $html);
        self::assertStringContainsString('Regenerate key', $html);
        // The handler is a static component bound via data-mage-init, not an inline script.
        self::assertStringContainsString('data-mage-init', $html);
        self::assertStringContainsString('MageDevGroup_TypesenseInstantSearch/js/regenerate', $html);
        self::assertStringNotContainsString('<script', $html);
    }
}
