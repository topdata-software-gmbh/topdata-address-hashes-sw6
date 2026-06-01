<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:address-hashes:refresh',
    description: 'Recalculates all address hashes for existing entries'
)]
class Command_RefreshHashes extends Command
{
    public function __construct(
        private readonly HashLogicService $hashLogicService
    ) {
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        CliLogger::setCliStyle(new SymfonyStyle($input, $output));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::info('Refreshing hashes for customer_address and order_address...');

        try {
            $this->hashLogicService->refreshAllHashes();
            CliLogger::success('Successfully refreshed all address hashes.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            CliLogger::error('An error occurred while refreshing hashes: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}