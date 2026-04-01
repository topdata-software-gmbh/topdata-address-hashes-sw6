<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Doctrine\DBAL\Connection;

class TriggerManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HashLogicService $hashLogicService
    ) {
    }

    public function updateAllTriggers(): void
    {
        $this->setupTriggersForTable('customer_address', 'tdah_customer_address_extension');
        $this->setupTriggersForTable('order_address', 'tdah_order_address_extension');
    }

    private function setupTriggersForTable(string $coreTable, string $extensionTable): void
    {
        $triggerIns = "tdah_{$coreTable}_ins";
        $triggerUpd = "tdah_{$coreTable}_upd";
        $hasVersion = $coreTable !== 'customer_address';
        $hashExpr = $this->hashLogicService->getSqlExpression('NEW');

        $this->connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns`");
        $this->connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd`");

        $replaceColumns = $hasVersion
            ? '(address_id, address_version_id, fingerprint, created_at, updated_at)'
            : '(address_id, fingerprint, created_at, updated_at)';

        $replaceValues = $hasVersion
            ? "NEW.id, NEW.version_id, $hashExpr, NOW(3), NULL"
            : "NEW.id, $hashExpr, NOW(3), NULL";

        $replaceSelect = $hasVersion
            ? "SELECT NEW.id, NEW.version_id, $hashExpr, IFNULL(created_at, NOW(3)), NOW(3) FROM (SELECT 1) AS dummy LEFT JOIN `$extensionTable` ON address_id = NEW.id AND address_version_id = NEW.version_id"
            : "SELECT NEW.id, $hashExpr, IFNULL(created_at, NOW(3)), NOW(3) FROM (SELECT 1) AS dummy LEFT JOIN `$extensionTable` ON address_id = NEW.id";

        $this->connection->executeStatement("CREATE TRIGGER `$triggerIns` AFTER INSERT ON `$coreTable` FOR EACH ROW REPLACE INTO `$extensionTable` $replaceColumns VALUES ($replaceValues);");
        $this->connection->executeStatement("CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$coreTable` FOR EACH ROW REPLACE INTO `$extensionTable` $replaceColumns $replaceSelect;");
    }
}
