<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Observer;

use DmLab\TypesenseInstantSearch\Observer\EnsureKeysOnIndexPrefixChange;
use DmLab\TypesenseInstantSearch\Observer\ProvisionSearchKey;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class EnsureKeysOnIndexPrefixChangeTest extends TestCase
{
    public function testEnsuresKeysWhenTheIndexPrefixChanged(): void
    {
        $observer = $this->observer(['catalog/search/engine', 'catalog/search/typesense_index_prefix']);
        $provision = $this->createMock(ProvisionSearchKey::class);
        $provision->expects(self::once())->method('execute')->with($observer);

        (new EnsureKeysOnIndexPrefixChange($provision))->execute($observer);
    }

    public function testIgnoresOtherCatalogChanges(): void
    {
        $provision = $this->createMock(ProvisionSearchKey::class);
        $provision->expects(self::never())->method('execute');

        (new EnsureKeysOnIndexPrefixChange($provision))->execute($this->observer(['catalog/search/engine']));
        (new EnsureKeysOnIndexPrefixChange($provision))->execute($this->observer(null));
    }

    /**
     * @param string[]|null $changedPaths
     */
    private function observer(?array $changedPaths): Observer
    {
        return new Observer(['event' => new Event(['changed_paths' => $changedPaths])]);
    }
}
