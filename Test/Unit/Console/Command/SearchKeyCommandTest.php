<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Test\Unit\Console\Command;

use DmLab\TypesenseInstantSearch\Console\Command\SearchKeyEnsureCommand;
use DmLab\TypesenseInstantSearch\Console\Command\SearchKeyRegenerateCommand;
use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class SearchKeyCommandTest extends TestCase
{
    /** @var SearchKeyProvisioner&MockObject */
    private $provisioner;

    /** @var StoreRepositoryInterface&MockObject */
    private $storeRepository;

    protected function setUp(): void
    {
        $this->provisioner = $this->createMock(SearchKeyProvisioner::class);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
    }

    public static function commands(): array
    {
        return [
            'ensure' => [SearchKeyEnsureCommand::class, 'ensure', 'dmlab:typesense:search-key:ensure'],
            'regenerate' => [SearchKeyRegenerateCommand::class, 'regenerate', 'dmlab:typesense:search-key:regenerate'],
        ];
    }

    #[DataProvider('commands')]
    public function testName(string $class, string $method, string $name): void
    {
        self::assertSame($name, $this->command($class)->getName());
    }

    #[DataProvider('commands')]
    public function testAllStoresByDefaultWithOneLinePerStoreRole(string $class, string $method, string $name): void
    {
        $this->provisioner->expects(self::once())->method($method)->with(null)->willReturn([
            ['store' => 'default', 'role' => 'proxy', 'status' => 'created'],
            ['store' => 'de', 'role' => 'public', 'status' => 'rotated'],
        ]);

        $tester = new CommandTester($this->command($class));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("default proxy: created\nde public: rotated\n", $tester->getDisplay());
    }

    #[DataProvider('commands')]
    public function testStoreOptionResolvesTheCode(string $class, string $method, string $name): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $this->storeRepository->method('get')->with('de')->willReturn($store);
        $this->provisioner->expects(self::once())->method($method)->with(2)
            ->willReturn([['store' => 'de', 'role' => 'proxy', 'status' => 'exists']]);

        $tester = new CommandTester($this->command($class));

        self::assertSame(Command::SUCCESS, $tester->execute(['--store' => 'de']));
        self::assertStringContainsString('de proxy: exists', $tester->getDisplay());
    }

    #[DataProvider('commands')]
    public function testUnknownStoreCodeFails(string $class, string $method, string $name): void
    {
        $this->storeRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('The store that was requested wasn\'t found.')));
        $this->provisioner->expects(self::never())->method($method);

        $tester = new CommandTester($this->command($class));

        self::assertSame(Command::FAILURE, $tester->execute(['--store' => 'nope']));
        self::assertStringContainsString('wasn\'t found', $tester->getDisplay());
    }

    #[DataProvider('commands')]
    public function testProvisionerFailureFailsWithMessage(string $class, string $method, string $name): void
    {
        $this->provisioner->method($method)->willThrowException(new \RuntimeException('No admin API key.'));

        $tester = new CommandTester($this->command($class));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No admin API key.', $tester->getDisplay());
    }

    private function command(string $class): Command
    {
        return new $class($this->provisioner, $this->storeRepository);
    }
}
