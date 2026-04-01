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
        $this->createExtensionTable($connection, 'tdah_customer_address_extension');
        $this->createExtensionTable($connection, 'tdah_order_address_extension');

        $this->createTriggers($connection);
    }

    private function createExtensionTable(Connection $connection, string $tableName): void
    {
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS `$tableName` (
                `address_id` BINARY(16) NOT NULL,
                `address_version_id` BINARY(16) NOT NULL,
                `fingerprint` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`address_id`, `address_version_id`),
                INDEX `idx.fingerprint` (`fingerprint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        );
    }

    private function createTriggers(Connection $connection): void
    {
        $this->setupTriggersForTable($connection, 'customer_address', 'tdah_customer_address_extension');
        $this->setupTriggersForTable($connection, 'order_address', 'tdah_order_address_extension');
    }

    private function setupTriggersForTable(Connection $connection, string $coreTable, string $extensionTable): void
    {
        $triggerIns = "tdah_{$coreTable}_ins";
        $triggerUpd = "tdah_{$coreTable}_upd";

        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns`");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd`");

        $hashExpr = "SHA2(LOWER(CONCAT(
            REGEXP_REPLACE(IFNULL(NEW.street, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.zipcode, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.city, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.phone_number, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.additional_address_line1, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.additional_address_line2, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.company, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.department, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(NEW.salutation_id), ''),
            REGEXP_REPLACE(IFNULL(NEW.first_name, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.last_name, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.title, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(NEW.country_id), '')
        )), 256)";

        $versionExpr = $coreTable === 'customer_address'
            ? "UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')"
            : 'NEW.version_id';

        $connection->executeStatement(
            "CREATE TRIGGER `$triggerIns` AFTER INSERT ON `$coreTable`
            FOR EACH ROW
            REPLACE INTO `$extensionTable` (address_id, address_version_id, fingerprint, created_at, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3), NULL);"
        );

        $connection->executeStatement(
            "CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$coreTable`
            FOR EACH ROW
            REPLACE INTO `$extensionTable` (address_id, address_version_id, fingerprint, created_at, updated_at)
            SELECT NEW.id, $versionExpr, $hashExpr, IFNULL(created_at, NOW(3)), NOW(3)
            FROM (SELECT 1) AS dummy
            LEFT JOIN `$extensionTable` ON address_id = NEW.id AND address_version_id = $versionExpr;"
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_customer_address_ins`");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_customer_address_upd`");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_order_address_ins`");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_order_address_upd`");
        $connection->executeStatement("DROP TABLE IF EXISTS `tdah_customer_address_extension`");
        $connection->executeStatement("DROP TABLE IF EXISTS `tdah_order_address_extension`");
    }
}