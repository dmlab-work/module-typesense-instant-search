<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Doubles;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;

/**
 * Phrase renderer that wraps output in `tr(…)`, proving a string went through `__()`.
 */
class MarkingPhraseRenderer implements RendererInterface
{
    private static ?RendererInterface $previous = null;

    /**
     * Install as the global Phrase renderer.
     */
    public static function install(): void
    {
        self::$previous = Phrase::getRenderer();
        Phrase::setRenderer(new self());
    }

    /**
     * Restore the renderer active before {@see install()}.
     */
    public static function uninstall(): void
    {
        if (self::$previous !== null) {
            Phrase::setRenderer(self::$previous);
            self::$previous = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function render(array $source, array $arguments)
    {
        $text = (string)end($source);
        foreach (array_values($arguments) as $i => $argument) {
            $text = str_replace('%' . ($i + 1), (string)$argument, $text);
        }

        return 'tr(' . $text . ')';
    }
}
