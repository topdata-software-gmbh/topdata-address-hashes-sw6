<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'topdata:address-hashes:refresh',
    description: 'Recalculates all address hashes for existing entries'
)]
class Command_RefreshHashes extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Refreshing hashes for customer_address...');
        $this->refreshTable('customer_address', 'tdah_customer_address_extension');

        $output->writeln('Refreshing hashes for order_address...');
        $this->refreshTable('order_address', 'tdah_order_address_extension', 'version_id');

        $output->writeln('<info>Successfully refreshed all address hashes.</info>');

        return Command::SUCCESS;
    }

    private function refreshTable(string $table, string $extensionTable, ?string $versionField = null): void
    {
        $hashExpr = "SHA2(LOWER(CONCAT(
            REGEXP_REPLACE(IFNULL(street, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(zipcode, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(city, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(phone_number, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(additional_address_line1, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(additional_address_line2, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(company, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(department, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(salutation_id), ''),
            REGEXP_REPLACE(IFNULL(first_name, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(last_name, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(title, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(country_id), '')
        )), 256)";

        $insertColumns = $versionField !== null
            ? '(address_id, address_version_id, fingerprint, created_at, updated_at)'
            : '(address_id, fingerprint, created_at, updated_at)';
        $selectColumns = $versionField !== null
            ? "id, $versionField, $hashExpr, NOW(3), NULL"
            : "id, $hashExpr, NOW(3), NULL";

        $this->connection->executeStatement(
            "REPLACE INTO `$extensionTable` $insertColumns
            SELECT $selectColumns FROM `$table`"
        );
    }
}