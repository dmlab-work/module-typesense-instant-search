<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * System-config frontend model rendering the "Regenerate key" button.
 *
 * Posts to {@see \MageDevGroup\TypesenseInstantSearch\Controller\Adminhtml\System\Config\RegenerateKey}
 * which re-provisions the search-only key, then reloads so the status field reflects the new key.
 * The form key travels in the request so the POST passes CSRF validation.
 *
 * The click handler is a static RequireJS component ({@see /view/adminhtml/web/js/regenerate.js})
 * bound via `data-mage-init` — no inline `<script>` is emitted. The controller URL and the form key
 * ride in the button's data attributes, both escaped for an HTML attribute.
 */
class RegenerateButton extends Field
{
    private const REGENERATE_ROUTE = 'magedevgroup_typesense/system_config/regeneratekey';

    /**
     * @param Context $context
     * @param FormKey $csrfFormKey
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly FormKey $csrfFormKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->buildHtml($this->getUrl(self::REGENERATE_ROUTE), $this->csrfFormKey->getFormKey());
    }

    /**
     * Build the button markup, wired to the static regenerate component via `data-mage-init`.
     *
     * @param string $url the regenerate controller URL
     * @param string $formKey the CSRF form key
     */
    public function buildHtml(string $url, string $formKey): string
    {
        $url = $this->escapeHtmlAttr($url);
        $formKey = $this->escapeHtmlAttr($formKey);
        $init = $this->escapeHtmlAttr(
            '{"MageDevGroup_TypesenseInstantSearch/js/regenerate": {}}'
        );

        return <<<HTML
<button type="button" class="action-default"
        data-mage-init="{$init}"
        data-regenerate-url="{$url}"
        data-form-key="{$formKey}">Regenerate key</button>
HTML;
    }
}
