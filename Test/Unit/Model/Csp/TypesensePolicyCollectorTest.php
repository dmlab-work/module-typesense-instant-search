<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseInstantSearch\Test\Unit\Model\Csp;

use MageDevGroup\TypesenseInstantSearch\Model\Config;
use MageDevGroup\TypesenseInstantSearch\Model\Csp\TypesensePolicyCollector;
use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use PHPUnit\Framework\TestCase;

class TypesensePolicyCollectorTest extends TestCase
{
    /** @var Config&\PHPUnit\Framework\MockObject\Stub */
    private $config;

    /** @var TypesensePolicyCollector */
    private TypesensePolicyCollector $collector;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->collector = new TypesensePolicyCollector($this->config);
    }

    public function testAddsConfiguredHostToConnectSrc(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getPublicHost')->willReturn('search.example.com');

        $result = $this->collector->collect([]);

        self::assertCount(1, $result);
        $policy = $result[0];
        self::assertInstanceOf(FetchPolicy::class, $policy);
        self::assertSame('connect-src', $policy->getId());
        self::assertSame(['search.example.com'], $policy->getHostSources());
        // noneAllowed must be false — otherwise the host would be meaningless.
        self::assertFalse($policy->isNoneAllowed());
        self::assertStringContainsString('search.example.com', $policy->getValue());
    }

    public function testPreservesDefaultPoliciesWhenAddingHost(): void
    {
        $existing = $this->createStub(PolicyInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getPublicHost')->willReturn('search.example.com');

        $result = $this->collector->collect([$existing]);

        self::assertCount(2, $result);
        self::assertSame($existing, $result[0]);
        self::assertInstanceOf(FetchPolicy::class, $result[1]);
    }

    public function testDisabledLeavesPoliciesUntouched(): void
    {
        $existing = $this->createStub(PolicyInterface::class);
        $this->config->method('isEnabled')->willReturn(false);
        // The host must never be consulted when the widget is off.
        $this->config->method('getPublicHost')
            ->willThrowException(new \LogicException('public host read while disabled'));

        self::assertSame([$existing], $this->collector->collect([$existing]));
    }

    public function testProxyModeLeavesPoliciesUntouched(): void
    {
        $existing = $this->createStub(PolicyInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(false);
        // Proxy mode is same-origin, so the host must never be consulted.
        $this->config->method('getPublicHost')
            ->willThrowException(new \LogicException('public host read in proxy mode'));

        self::assertSame([$existing], $this->collector->collect([$existing]));
    }

    public function testMissingHostLeavesPoliciesUntouched(): void
    {
        $existing = $this->createStub(PolicyInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getPublicHost')->willReturn(null);

        self::assertSame([$existing], $this->collector->collect([$existing]));
    }

    public function testHostChangeIsReflectedWithoutCodeChange(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDirectMode')->willReturn(true);
        $this->config->method('getPublicHost')
            ->willReturnOnConsecutiveCalls('first.example.com', 'second.example.com');

        $first = $this->collector->collect([]);
        $second = $this->collector->collect([]);

        self::assertSame(['first.example.com'], $first[0]->getHostSources());
        self::assertSame(['second.example.com'], $second[0]->getHostSources());
    }
}
