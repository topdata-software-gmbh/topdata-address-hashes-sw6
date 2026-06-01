<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;

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

    private function refreshTable(string $table, string $extensionTable, ?string $versionField, string $hashFieldsSqlLiteral): void
    {
        $hashExpr = $this->hashLogicService->getSqlExpression($table);

        $insertColumns = $versionField !== null
            ? '(address_id, address_version_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)'
            : '(address_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)';

        $selectColumns = $versionField !== null
            ? "id, {$versionField}, {$hashExpr}, {$hashFieldsSqlLiteral}, NOW(3), NOW(3), NOW(3), NULL"
            : "id, {$hashExpr}, {$hashFieldsSqlLiteral}, NOW(3), NOW(3), NOW(3), NULL";

        $this->connection->executeStatement(
            "REPLACE INTO `{$extensionTable}` {$insertColumns}
            SELECT {$selectColumns} FROM `{$table}`"
        );
    }
}
