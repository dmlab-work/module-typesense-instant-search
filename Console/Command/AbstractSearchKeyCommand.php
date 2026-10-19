<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseInstantSearch\Console\Command;

use DmLab\TypesenseInstantSearch\Model\Key\SearchKeyProvisioner;
use Magento\Store\Api\StoreRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared `--store=<code>` handling and per-store/role reporting for the key commands.
 */
abstract class AbstractSearchKeyCommand extends Command
{
    private const OPTION_STORE = 'store';

    /**
     * @param SearchKeyProvisioner $provisioner
     * @param StoreRepositoryInterface $storeRepository
     * @param string|null $name
     */
    public function __construct(
        protected readonly SearchKeyProvisioner $provisioner,
        private readonly StoreRepositoryInterface $storeRepository,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Run the provisioner for one store id, or all stores when null.
     *
     * @param int|null $storeId
     * @return array<int,array{store:string,role:string,status:string}>
     */
    abstract protected function provision(?int $storeId): array;

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addOption(
            self::OPTION_STORE,
            null,
            InputOption::VALUE_REQUIRED,
            'Store view code (default: all store views)'
        );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $code = $input->getOption(self::OPTION_STORE);
            $storeId = $code === null ? null : (int)$this->storeRepository->get((string)$code)->getId();
            foreach ($this->provision($storeId) as $line) {
                $output->writeln(sprintf('%s %s: %s', $line['store'], $line['role'], $line['status']));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
