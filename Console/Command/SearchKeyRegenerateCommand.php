<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Console\Command;

/**
 * Rotates the per-store parent keys (create new, then delete old).
 */
class SearchKeyRegenerateCommand extends AbstractSearchKeyCommand
{
    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('dmlab:typesense:search-key:regenerate');
        $this->setDescription('Rotate Typesense instant search keys');

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function provision(?int $storeId): array
    {
        return $this->provisioner->regenerate($storeId);
    }
}
