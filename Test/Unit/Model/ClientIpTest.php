<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Model;

use DmLab\TypesenseInstantSearch\Model\ClientIp;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ClientIpTest extends TestCase
{
    /**
     * Client IP resolver over a request with the given server values.
     *
     * @param string[] $trustedProxies
     */
    private function clientIp(string $remote, ?string $forwarded, array $trustedProxies = []): ClientIp
    {
        $request = $this->createMock(Http::class);
        $request->method('getServerValue')->willReturnMap([
            ['REMOTE_ADDR', null, $remote],
            ['HTTP_X_FORWARDED_FOR', null, $forwarded],
        ]);

        return new ClientIp($request, $trustedProxies);
    }

    public function testWithoutTrustedProxiesSpoofedForwardedForIsIgnored(): void
    {
        self::assertSame('198.51.100.9', $this->clientIp('198.51.100.9', '1.2.3.4')->get());
    }

    public function testUntrustedPeerIgnoresForwardedFor(): void
    {
        self::assertSame(
            '198.51.100.9',
            $this->clientIp('198.51.100.9', '1.2.3.4', ['10.0.0.1'])->get()
        );
    }

    public function testTrustedProxyYieldsRightMostUntrustedHop(): void
    {
        self::assertSame(
            '203.0.113.7',
            $this->clientIp('10.0.0.1', '1.2.3.4, 203.0.113.7, 10.0.0.2', ['10.0.0.1', '10.0.0.2'])->get()
        );
    }

    public function testAllHopsTrustedFallsBackToRemoteAddr(): void
    {
        self::assertSame(
            '10.0.0.1',
            $this->clientIp('10.0.0.1', '10.0.0.2, 10.0.0.1', ['10.0.0.1', '10.0.0.2'])->get()
        );
    }

    public function testMissingForwardedForBehindTrustedProxyReturnsRemoteAddr(): void
    {
        self::assertSame('10.0.0.1', $this->clientIp('10.0.0.1', null, ['10.0.0.1'])->get());
    }

    public function testInvalidHopsAreSkipped(): void
    {
        self::assertSame(
            '2001:db8::5',
            $this->clientIp('10.0.0.1', '2001:db8::5, unknown, , 999.1.1.1', ['10.0.0.1'])->get()
        );
    }
}
