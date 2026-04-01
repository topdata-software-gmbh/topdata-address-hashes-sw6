<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1760000000AddCreatedAtToAddressHashTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'ALTER TABLE `tdah_address_hash`
                ADD COLUMN IF NOT EXISTS `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER `fingerprint`'
        );

        $this->_createTrigger($connection, 'customer_address');
        $this->_createTrigger($connection, 'order_address');
    }

    protected function _createTrigger(Connection $connection, string $table): void
    {
        $triggerIns = "tdah_{$table}_ins";
        $triggerUpd = "tdah_{$table}_upd";

        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns`");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd`");

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
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, fingerprint, created_at, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3), NOW(3));"
        );

        $connection->executeStatement(
            "CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$table`
            FOR EACH ROW
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, fingerprint, created_at, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3), NOW(3));"
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
