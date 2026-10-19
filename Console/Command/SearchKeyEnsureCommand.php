<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Console\Command;

/**
 * Creates every missing per-store parent key; existing keys are kept.
 */
class SearchKeyEnsureCommand extends AbstractSearchKeyCommand
{
    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('dmlab:typesense:search-key:ensure');
        $this->setDescription('Create missing Typesense instant search keys');

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function provision(?int $storeId): array
    {
        return $this->provisioner->ensure($storeId);
    }
}
