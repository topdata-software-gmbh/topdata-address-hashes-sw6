<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class Migration1748764800AddHashMetaColumns extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748764800;
    }

    public function update(Connection $connection): void
    {
        $this->_addMetaColumns($connection, 'tdah_customer_address_extension');
        $this->_addMetaColumns($connection, 'tdah_order_address_extension');

        $triggerManager = new TriggerManager($connection, new HashLogicService($connection));
        $triggerManager->updateAllTriggers();
    }

    private function _addMetaColumns(Connection $connection, string $tableName): void
    {
        $columns = $connection->fetchFirstColumn("SHOW COLUMNS FROM `{$tableName}`");
        $columns = array_map('strtolower', $columns);

        if (!in_array('hash_fields', $columns, true)) {
            $connection->executeStatement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `hash_fields` JSON NULL AFTER `fingerprint`"
            );
        }

        if (!in_array('hash_fields_changed_at', $columns, true)) {
            $connection->executeStatement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `hash_fields_changed_at` DATETIME(3) NULL AFTER `hash_fields`"
            );
        }

        if (!in_array('hash_changed_at', $columns, true)) {
            $connection->executeStatement(
                "ALTER TABLE `{$tableName}` ADD COLUMN `hash_changed_at` DATETIME(3) NULL AFTER `hash_fields_changed_at`"
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
