<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1716380000CreateAddressHashTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1716380000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS `tdah_address_hash` (
                `address_id` BINARY(16) NOT NULL,
                `address_version_id` BINARY(16) NOT NULL,
                `hash_value` VARCHAR(64) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`address_id`, `address_version_id`),
                INDEX `idx.tdah_hash_value` (`hash_value`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        );

        $this->createTrigger($connection, 'customer_address');
        $this->createTrigger($connection, 'order_address');
    }

    private function createTrigger(Connection $connection, string $table): void
    {
        $triggerIns = "tdah_{$table}_ins";
        $triggerUpd = "tdah_{$table}_upd";

        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns`");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd`");

        // Hash: normalized street + zipcode + city + country id.
        $hashExpr = "SHA2(LOWER(CONCAT(
            REGEXP_REPLACE(IFNULL(NEW.street, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.zipcode, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.city, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(NEW.country_id), '')
        )), 256)";

        $versionExpr = $table === 'customer_address'
            ? "UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')"
            : 'NEW.version_id';

        $connection->executeStatement(
            "CREATE TRIGGER `$triggerIns` AFTER INSERT ON `$table`
            FOR EACH ROW
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, hash_value, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3));"
        );

        $connection->executeStatement(
            "CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$table`
            FOR EACH ROW
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, hash_value, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3));"
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}