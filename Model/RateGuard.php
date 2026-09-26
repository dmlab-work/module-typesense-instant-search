<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model;

use Magento\Framework\App\CacheInterface;

/**
 * Lightweight fixed-window per-identifier request guard for the proxy endpoint.
 *
 * The proxy runs one Typesense query per keystroke on the merchant's own server, so a scripted
 * caller could turn it into a query amplifier. This caps requests per identifier (the client IP)
 * over a short window using the shared cache — coarse on purpose: it throttles abuse without a
 * dedicated store or per-request write cost. An empty identifier is always allowed (fail open).
 */
class RateGuard
{
    /** Cache key prefix for the per-identifier counter. */
    private const CACHE_PREFIX = 'dmlab_typesense_is_rate_';

    /** Cache tag so the counters clear with the app cache. */
    private const CACHE_TAG = 'DMLAB_TYPESENSE_INSTANT_SEARCH_RATE';

    /**
     * @param CacheInterface $cache shared app cache backing the counters
     * @param int $maxRequests requests allowed per identifier within the window
     * @param int $windowSeconds window length in seconds
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxRequests = 30,
        private readonly int $windowSeconds = 10
    ) {
    }

    /**
     * Whether a request from the identifier is allowed, counting it when it is.
     *
     * @param string $identifier the caller identity (client IP); empty is always allowed
     */
    public function allow(string $identifier): bool
    {
        if ($identifier === '' || $this->maxRequests < 1) {
            return true;
        }

        $key = self::CACHE_PREFIX . sha1($identifier);
        $count = (int)$this->cache->load($key);
        if ($count >= $this->maxRequests) {
            return false;
        }

        $this->cache->save((string)($count + 1), $key, [self::CACHE_TAG], $this->windowSeconds);

        return true;
    }
}
