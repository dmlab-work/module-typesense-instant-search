<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The two storefront query modes: proxy (default, Typesense stays private) and direct
 * (browser queries Typesense with a scoped read-only key).
 */
class Mode implements OptionSourceInterface
{
    public const PROXY = 'proxy';
    public const DIRECT = 'direct';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PROXY, 'label' => __('Proxy (Typesense stays private)')],
            ['value' => self::DIRECT, 'label' => __('Direct (browser queries Typesense)')],
        ];
    }
}
