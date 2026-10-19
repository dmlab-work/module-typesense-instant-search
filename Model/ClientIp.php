<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Model;

use Magento\Framework\App\Request\Http;

/**
 * Client IP for the proxy rate guard that a caller cannot spoof.
 *
 * `X-Forwarded-For` is consulted only when the direct peer is a configured trusted proxy; then the
 * right-most hop that is not itself a trusted proxy is the client (left-most hops are client-supplied).
 */
class ClientIp
{
    /**
     * @param Http $request the current HTTP request
     * @param string[] $trustedProxies exact IPs of reverse proxies allowed to set `X-Forwarded-For`
     */
    public function __construct(
        private readonly Http $request,
        private readonly array $trustedProxies = []
    ) {
    }

    /**
     * The real client IP, or `REMOTE_ADDR` when no trusted proxy is in front.
     */
    public function get(): string
    {
        $remote = trim((string)$this->request->getServerValue('REMOTE_ADDR'));
        if (!$this->isTrusted($remote)) {
            return $remote;
        }

        $hops = explode(',', (string)$this->request->getServerValue('HTTP_X_FORWARDED_FOR'));
        foreach (array_reverse($hops) as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP) !== false && !$this->isTrusted($hop)) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * Whether the IP is one of the configured trusted proxies.
     *
     * @param string $ip candidate address
     */
    private function isTrusted(string $ip): bool
    {
        return $ip !== '' && in_array($ip, array_map('trim', $this->trustedProxies), true);
    }
}
