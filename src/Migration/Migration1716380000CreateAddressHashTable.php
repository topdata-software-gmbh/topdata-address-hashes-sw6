<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class Migration1716380000CreateAddressHashTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1716380000;
    }

    public function update(Connection $connection): void
    {
        $this->_createExtensionTable($connection, 'tdah_customer_address_extension');
        $this->_createExtensionTable($connection, 'tdah_order_address_extension');

        $this->_createForeignKeyIfMissing(
            $connection,
            'tdah_customer_address_extension',
            'fk.tdah_customer.address_id',
            'ALTER TABLE `tdah_customer_address_extension`
                ADD CONSTRAINT `fk.tdah_customer.address_id`
                FOREIGN KEY (`address_id`) REFERENCES `customer_address` (`id`) ON DELETE CASCADE ON UPDATE CASCADE'
        );

        $this->_createForeignKeyIfMissing(
            $connection,
            'tdah_order_address_extension',
            'fk.tdah_order.address_id',
            'ALTER TABLE `tdah_order_address_extension`
                ADD CONSTRAINT `fk.tdah_order.address_id`
                FOREIGN KEY (`address_id`, `address_version_id`) REFERENCES `order_address` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE'
        );

        $this->_createTriggers($connection);
    }

    private function _createExtensionTable(Connection $connection, string $tableName): void
    {
        $hasVersion = $tableName !== 'tdah_customer_address_extension';
        $versionColumn = $hasVersion ? "`address_version_id` BINARY(16) NOT NULL," : '';
        $primaryKey = $hasVersion ? 'PRIMARY KEY (`address_id`, `address_version_id`)' : 'PRIMARY KEY (`address_id`)';

        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS `$tableName` (
                `address_id` BINARY(16) NOT NULL,
                $versionColumn
                `fingerprint` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                $primaryKey,
                INDEX `idx.fingerprint` (`fingerprint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        );
    }

    private function _createForeignKeyIfMissing(
        Connection $connection,
        string $tableName,
        string $constraintName,
        string $ddl
    ): void
    {
        $exists = (int) $connection->fetchOne(
            'SELECT COUNT(*)
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = :tableName
                AND CONSTRAINT_NAME = :constraintName',
            [
                'tableName' => $tableName,
                'constraintName' => $constraintName,
            ]
        );

        if ($exists > 0) {
            return;
        }

        $constraintTableName = $connection->fetchOne(
            'SELECT TABLE_NAME
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME = :constraintName
            LIMIT 1',
            [
                'constraintName' => $constraintName,
            ]
        );

        if (
            is_string($constraintTableName)
            && $constraintTableName !== ''
            && $constraintTableName !== $tableName
        ) {
            $alternateConstraintName = substr($constraintName . '_tdah', 0, 64);
            $ddl = str_replace(
                "`$constraintName`",
                "`$alternateConstraintName`",
                $ddl
            );
        }

        $connection->executeStatement($ddl);
    }


    private function _createTriggers(Connection $connection): void
    {
        $triggerManager = new TriggerManager($connection, new HashLogicService());
        $triggerManager->updateAllTriggers();
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