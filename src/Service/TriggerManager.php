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

    public function updateAllTriggers(?string $hashFieldsChangedAt = null): void
    {
        $hashFieldsJson = $this->hashLogicService->getHashFieldsJson();

        $this->setupTriggersForTable(
            'customer_address',
            'tdah_customer_address_extension',
            $hashFieldsJson,
            $hashFieldsChangedAt
        );
        $this->setupTriggersForTable(
            'order_address',
            'tdah_order_address_extension',
            $hashFieldsJson,
            $hashFieldsChangedAt
        );
    }

    private function setupTriggersForTable(
        string $coreTable,
        string $extensionTable,
        string $hashFieldsJson,
        ?string $hashFieldsChangedAt
    ): void {
        $triggerIns = "tdah_{$coreTable}_ins";
        $triggerUpd = "tdah_{$coreTable}_upd";
        $hasVersion = $coreTable !== 'customer_address';
        $hashExpr = $this->hashLogicService->getSqlExpression('NEW');

        $hashFieldsSqlLiteral = "'" . str_replace("'", "''", $hashFieldsJson) . "'";
        $hashFieldsChangedAtSql = $hashFieldsChangedAt !== null
            ? "'" . str_replace("'", "''", $hashFieldsChangedAt) . "'"
            : 'NULL';

        $this->connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns`");
        $this->connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd`");

        $replaceColumns = $hasVersion
            ? '(address_id, address_version_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)'
            : '(address_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)';

        $replaceValues = $hasVersion
            ? "NEW.id, NEW.version_id, {$hashExpr}, {$hashFieldsSqlLiteral}, {$hashFieldsChangedAtSql}, NOW(3), NOW(3), NULL"
            : "NEW.id, {$hashExpr}, {$hashFieldsSqlLiteral}, {$hashFieldsChangedAtSql}, NOW(3), NOW(3), NULL";

        $replaceSelectColumns = $hasVersion
            ? "NEW.id, NEW.version_id, {$hashExpr}, {$hashFieldsSqlLiteral}, {$hashFieldsChangedAtSql}, NOW(3), IFNULL(created_at, NOW(3)), NOW(3)"
            : "NEW.id, {$hashExpr}, {$hashFieldsSqlLiteral}, {$hashFieldsChangedAtSql}, NOW(3), IFNULL(created_at, NOW(3)), NOW(3)";

        $replaceSelect = "SELECT {$replaceSelectColumns} FROM (SELECT 1) AS dummy LEFT JOIN `{$extensionTable}` ON address_id = NEW.id"
            . ($hasVersion ? ' AND address_version_id = NEW.version_id' : '');

        $this->connection->executeStatement(
            "CREATE TRIGGER `{$triggerIns}` AFTER INSERT ON `{$coreTable}` FOR EACH ROW REPLACE INTO `{$extensionTable}` {$replaceColumns} VALUES ({$replaceValues});"
        );

        $this->connection->executeStatement(
            "CREATE TRIGGER `{$triggerUpd}` AFTER UPDATE ON `{$coreTable}` FOR EACH ROW REPLACE INTO `{$extensionTable}` {$replaceColumns} {$replaceSelect};"
        );
    }
}
