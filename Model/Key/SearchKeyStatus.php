<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Key;

use DmLab\TypesenseInstantSearch\Model\SearchKeyProvider;

/**
 * Renders the read-only "is the search-only key provisioned?" status shown in admin config.
 *
 * The key is never pasted — it is auto-provisioned ({@see SearchKeyProvisioner}) — so the admin
 * field is a status line, not an input. Kept as a plain model so the config block is a thin adapter
 * and the presence logic is unit-testable without the backend block harness.
 */
class SearchKeyStatus
{
    /**
     * @param SearchKeyProvider $keyProvider tells whether a parent key exists
     */
    public function __construct(
        private readonly SearchKeyProvider $keyProvider
    ) {
    }

    /**
     * Status HTML: provisioned (with a check) when a parent key exists, otherwise not provisioned.
     */
    public function getHtml(): string
    {
        if ($this->keyProvider->hasParentKey()) {
            return '<span style="color:#1b7d33;font-weight:600;">Search key: provisioned ✓</span>';
        }

        return '<span style="color:#b30000;font-weight:600;">Search key: not provisioned</span>'
            . '<p class="note">Auto-created when direct mode is saved with the Typesense admin key set.</p>';
    }
}
