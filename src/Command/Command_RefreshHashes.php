<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:address-hashes:refresh',
    description: 'Recalculates all address hashes for existing entries'
)]
/**
 * Command to refresh address hashes for existing customer and order addresses.
 * This command recalculates all address hashes for existing entries in the database.
 */
class Command_RefreshHashes extends AbstractTopdataCommand
{
    public function __construct(
        private readonly HashLogicService $hashLogicService
    ) {
        parent::__construct();
    }

    /**
     * Initializes the command before execution.
     * Sets up the CLI style for logging.
     *
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        CliLogger::setCliStyle($this->cliStyle);
    }

    /**
     * Executes the command to refresh address hashes.
     * 
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     * @return int The exit code (SUCCESS or FAILURE)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- Start of hash refresh process ----
        CliLogger::info('Refreshing hashes for customer_address and order_address...');

        try {
            // ---- Execute the hash refresh logic ----
            $results = $this->hashLogicService->refreshAllHashes();

            // ---- Display per-table results ----
            $totalProcessed = 0;
            $totalInserted = 0;
            $totalUpdated = 0;

            foreach ($results as $table => $counts) {
                $totalProcessed += $counts['processed'];
                $totalInserted += $counts['inserted'];
                $totalUpdated += $counts['updated'];
                CliLogger::info(sprintf(
                    '  %s: %d processed (%d inserted, %d updated)',
                    $table, $counts['processed'], $counts['inserted'], $counts['updated']
                ));
            }

            // ---- Log success and return ----
            CliLogger::success(sprintf(
                'Successfully refreshed all address hashes (%d total, %d inserted, %d updated).',
                $totalProcessed, $totalInserted, $totalUpdated
            ));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // ---- Handle errors ----
            CliLogger::error('An error occurred while refreshing hashes: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}