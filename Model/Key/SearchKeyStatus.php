<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Key;

use DmLab\TypesenseInstantSearch\Model\Config;
use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;
use Magento\Framework\Escaper;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders the read-only per-store key status table shown in admin config.
 *
 * Kept as a plain model so the config block is a thin adapter and the logic is unit-testable.
 */
class SearchKeyStatus
{
    private const STYLE_OK = 'color:#1b7d33;font-weight:600;';
    private const STYLE_MISSING = 'color:#b30000;font-weight:600;';

    /**
     * @param SearchKeyProvider $keyProvider
     * @param StoreManagerInterface $storeManager
     * @param Escaper $escaper
     * @param Config $config
     */
    public function __construct(
        private readonly SearchKeyProvider $keyProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly Escaper $escaper,
        private readonly Config $config
    ) {
    }

    /**
     * Status HTML: one row per store view, one column per key role the mode uses (provisioned or missing).
     */
    public function getHtml(): string
    {
        $roles = $this->config->isDirectMode() ? StoreKeyRepository::ROLES : [StoreKeyRepository::ROLE_PROXY];
        $head = '<th>' . $this->escape(__('Store view')) . '</th>';
        foreach ($roles as $role) {
            $head .= '<th>' . $this->escape($this->roleLabel($role)) . '</th>';
        }

        $rows = '';
        $missing = false;
        foreach ($this->storeManager->getStores() as $store) {
            $rows .= '<tr><td>' . $this->escape((string)$store->getCode()) . '</td>';
            foreach ($roles as $role) {
                $present = $this->keyProvider->getParentKey((int)$store->getId(), $role) !== null;
                $missing = $missing || !$present;
                $rows .= $present
                    ? '<td style="' . self::STYLE_OK . '">' . $this->escape(__('Provisioned')) . '</td>'
                    : '<td style="' . self::STYLE_MISSING . '">' . $this->escape(__('Missing')) . '</td>';
            }
            $rows .= '</tr>';
        }

        $html = '<table class="data-grid"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        if ($missing) {
            $html .= '<p class="note">'
                . $this->escape(__('Missing keys are created on save when the Typesense admin key is set.'))
                . '</p>';
        }

        return $html;
    }

    /**
     * Translated column label of a key role.
     *
     * @param string $role
     */
    private function roleLabel(string $role): string
    {
        return $role === StoreKeyRepository::ROLE_PUBLIC
            ? (string)__('Public key (direct mode)')
            : (string)__('Proxy key');
    }

    /**
     * Escape a string or phrase for HTML.
     *
     * @param \Stringable|string $value
     */
    private function escape(\Stringable|string $value): string
    {
        return (string)$this->escaper->escapeHtml((string)$value);
    }
}
