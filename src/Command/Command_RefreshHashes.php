<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;

/**
 * Command to refresh address hashes for existing entries in the database.
 * This command processes customer_address and order_address tables to update
 * their corresponding extension tables with calculated hash values.
 */
#[AsCommand(
    name: 'topdata:address-hashes:refresh',
    description: 'Recalculates all address hashes for existing entries'
)]
class Command_RefreshHashes extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HashLogicService $hashLogicService
    ) {
        parent::__construct();
    }

    /**
     * Executes the command to refresh address hashes.
     * 
     * This method processes both customer_address and order_address tables,
     * updating their respective extension tables with new hash values.
     *
     * @param InputInterface $input The command input interface
     * @param OutputInterface $output The command output interface
     * @return int The command exit code (SUCCESS on completion)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hashFieldsJson = $this->hashLogicService->getHashFieldsJson();
        $hashFieldsSqlLiteral = "'" . str_replace("'", "''", $hashFieldsJson) . "'";

        $output->writeln('Refreshing hashes for customer_address...');
        $this->refreshTable('customer_address', 'tdah_customer_address_extension', null, $hashFieldsSqlLiteral);

        $output->writeln('Refreshing hashes for order_address...');
        $this->refreshTable('order_address', 'tdah_order_address_extension', 'version_id', $hashFieldsSqlLiteral);

        $output->writeln('<info>Successfully refreshed all address hashes.</info>');

        return Command::SUCCESS;
    }

    /**
     * Refreshes hash values for a specific address table.
     * 
     * This method replaces hash values in the extension table by calculating
     * new hashes based on the address data from the main table.
     *
     * @param string $table The main address table name (e.g., 'customer_address')
     * @param string $extensionTable The corresponding extension table name
     * @param string|null $versionField The version field name if applicable (for order_address)
     * @param string $hashFieldsSqlLiteral SQL literal containing hash field configuration
     */
    private function refreshTable(string $table, string $extensionTable, ?string $versionField, string $hashFieldsSqlLiteral): void
    {
        // ---- Prepare SQL expressions for hash calculation and field configuration
        $hashExpr = $this->hashLogicService->getSqlExpression($table);

        // ---- Build column lists based on whether version field is present
        $insertColumns = $versionField !== null
            ? '(address_id, address_version_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)'
            : '(address_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)';

        $selectColumns = $versionField !== null
            ? "id, {$versionField}, {$hashExpr}, {$hashFieldsSqlLiteral}, NOW(3), NOW(3), NOW(3), NULL"
            : "id, {$hashExpr}, {$hashFieldsSqlLiteral}, NOW(3), NOW(3), NOW(3), NULL";

        // ---- Execute REPLACE statement to update extension table with new hash values
        $this->connection->executeStatement(
            "REPLACE INTO `{$extensionTable}` {$insertColumns}
            SELECT {$selectColumns} FROM `{$table}`"
        );
    }
}