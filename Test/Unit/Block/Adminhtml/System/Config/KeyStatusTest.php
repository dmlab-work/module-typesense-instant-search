<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\Block\Adminhtml\System\Config;

use MageDevGroup\TypesenseInstantSearch\Block\Adminhtml\System\Config\KeyStatus;
use MageDevGroup\TypesenseInstantSearch\Model\Key\SearchKeyStatus;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;

/**
 * The frontend model is a pure adapter: the field renders exactly what {@see SearchKeyStatus}
 * produces. Instantiated without the heavy block constructor (the delegation is all that matters).
 */
class KeyStatusTest extends TestCase
{
    public function testElementHtmlDelegatesToTheStatusModel(): void
    {
        $status = $this->createStub(SearchKeyStatus::class);
        $status->method('getHtml')->willReturn('<span>Search key: provisioned ✓</span>');

        $block = (new \ReflectionClass(KeyStatus::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(KeyStatus::class, 'status');
        $property->setValue($block, $status);

        $method = new \ReflectionMethod(KeyStatus::class, '_getElementHtml');
        $html = $method->invoke($block, $this->createStub(AbstractElement::class));

        self::assertSame('<span>Search key: provisioned ✓</span>', $html);
    }
}
