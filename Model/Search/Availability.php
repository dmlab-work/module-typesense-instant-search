<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model\Search;

use Magento\Framework\App\CacheInterface;

/**
 * Short-lived "Typesense is down" flag, so a dead cluster costs one timeout per window, not per keystroke.
 */
class Availability
{
    private const CACHE_KEY = 'dmlab_typesense_is_down';

    private const CACHE_TAG = 'DMLAB_TYPESENSE_INSTANT_SEARCH';

    private const TTL = 30;

    /**
     * @param CacheInterface $cache shared app cache holding the flag
     */
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Whether Typesense was marked unreachable within the last window.
     */
    public function isDown(): bool
    {
        return (bool)$this->cache->load(self::CACHE_KEY);
    }

    /**
     * Mark Typesense unreachable for the window.
     */
    public function markDown(): void
    {
        $this->cache->save('1', self::CACHE_KEY, [self::CACHE_TAG], self::TTL);
    }
}
