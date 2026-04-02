<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Doctrine\DBAL\Connection;

/**
 * Manages database triggers for address hash functionality in Shopware 6.
 * This service sets up and maintains triggers that automatically update hash values
 * in extension tables when address records are inserted or updated in core tables.
 */
class TriggerManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HashLogicService $hashLogicService
    ) {
    }

    /**
     * Updates all triggers for both customer and order address tables.
     * This method ensures that the triggers are properly set up to maintain
     * hash values in the extension tables.
     */
    public function updateAllTriggers(): void
    {
        $this->setupTriggersForTable('customer_address', 'tdah_customer_address_extension');
        $this->setupTriggersForTable('order_address', 'tdah_order_address_extension');
    }

    /**
     * Sets up INSERT and UPDATE triggers for a specific address table.
     * These triggers automatically update the hash values in the extension table
     * when records are modified in the core table.
     *
     * @param string $coreTable The name of the core address table (e.g., 'customer_address')
     * @param string $extensionTable The name of the extension table where hashes are stored
     */
    private function setupTriggersForTable(string $coreTable, string $extensionTable): void
    {
        // ---- Generate trigger names based on core table name ----
        $triggerIns = "tdah_{$coreTable}_ins";
        $triggerUpd = "tdah_{$coreTable}_upd";
        
        // ---- Determine if table has versioning (order addresses do, customer addresses don't) ----
        $hasVersion = $coreTable !== 'customer_address';
        
        // ---- Get SQL expression for hash calculation ----
        $hashExpr = $this->hashLogicService->getSqlExpression('NEW');

        // ---- Drop existing triggers if they exist ----
        $this->connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns`");
        $this->connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd`");

        // ---- Prepare column and value lists based on versioning ----
        $replaceColumns = $hasVersion
            ? '(address_id, address_version_id, fingerprint, created_at, updated_at)'
            : '(address_id, fingerprint, created_at, updated_at)';

        $replaceValues = $hasVersion
            ? "NEW.id, NEW.version_id, $hashExpr, NOW(3), NULL"
            : "NEW.id, $hashExpr, NOW(3), NULL";

        // ---- Prepare SELECT statement for UPDATE trigger ----
        $replaceSelect = $hasVersion
            ? "SELECT NEW.id, NEW.version_id, $hashExpr, IFNULL(created_at, NOW(3)), NOW(3) FROM (SELECT 1) AS dummy LEFT JOIN `$extensionTable` ON address_id = NEW.id AND address_version_id = NEW.version_id"
            : "SELECT NEW.id,                 $hashExpr, IFNULL(created_at, NOW(3)), NOW(3) FROM (SELECT 1) AS dummy LEFT JOIN `$extensionTable` ON address_id = NEW.id";

        // ---- Create the INSERT trigger ----
        $this->connection->executeStatement("CREATE TRIGGER `$triggerIns` AFTER INSERT ON `$coreTable` FOR EACH ROW REPLACE INTO `$extensionTable` $replaceColumns VALUES ($replaceValues);");
        
        // ---- Create the UPDATE trigger ----
        $this->connection->executeStatement("CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$coreTable` FOR EACH ROW REPLACE INTO `$extensionTable` $replaceColumns $replaceSelect;");
    }
}