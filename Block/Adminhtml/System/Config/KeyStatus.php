<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Block\Adminhtml\System\Config;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyStatus;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * System-config frontend model for the read-only search-key status field.
 *
 * Thin adapter: the field stores no value; it renders {@see SearchKeyStatus} in place of the old
 * paste box.
 */
class KeyStatus extends Field
{
    /**
     * @param Context $context
     * @param SearchKeyStatus $status
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly SearchKeyStatus $status,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->status->getHtml();
    }
}
