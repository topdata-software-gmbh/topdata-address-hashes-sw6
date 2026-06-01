---
filename: "_ai/backlog/active/260601_1627__IMPLEMENTATION_PLAN__address-hash-meta-columns.md"
title: "Add Hash Metadata Columns to Address Hash Extension Tables"
createdAt: 2026-06-01 16:27
updatedAt: 2026-06-01 16:27
status: draft
priority: high
tags: [shopware, database, migration, hash, metadata]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement

The `topdata-address-hashes-sw6` plugin stores address fingerprint hashes in two extension tables (`tdah_customer_address_extension` and `tdah_order_address_extension`). Each row currently contains only `address_id`, `fingerprint`, `created_at`, and `updated_at`.

When the admin changes which fields are included in the hash configuration (e.g., adding `company` or removing `city`), all existing hashes become **stale** — they were computed with the old field set but there is no way to detect this. The only signal is the absence of correspondence between the current config and the hash, but this requires a full recalculation run to reconcile.

There is no stored record of:
1. **Which fields** were used to compute a given hash
2. **When the field configuration** was last changed
3. **When the hash** itself was last computed

This makes it impossible to programmatically identify stale hashes, audit config changes, or troubleshoot discrepancies.

---

# Executive Summary

Add three new columns to both extension tables:

| Column | Type | Purpose |
|---|---|---|
| `hash_fields` | `JSON` | Stores the sorted JSON array of field names used to compute this hash (e.g., `["street","zipcode","city","lastName","countryId"]`) |
| `hash_fields_changed_at` | `DATETIME(3)` NULL | Timestamp of when the field configuration used for this hash was saved/changed |
| `hash_changed_at` | `DATETIME(3)` NULL | Timestamp of when the fingerprint was last computed |

These columns are populated:
- **By DB triggers** on address INSERT/UPDATE — `hash_fields` and `hash_fields_changed_at` are embedded as SQL literals (set when triggers are recreated), `hash_changed_at` uses `NOW(3)`
- **By `topdata:address-hashes:refresh`** command — all three columns are set via SQL expressions
- **Config changes** — `ConfigChangeSubscriber` passes the current timestamp to `TriggerManager`, which embeds it in the trigger SQL

This enables detecting stale hashes by comparing `hash_fields` with the current config, auditing when config changes occurred, and tracking hash freshness.

---

# Project Environment

- **Project Name:** topdata-address-hashes-sw6
- **Backend root:** src
- **PHP Version:** 8.2 / 8.3 / 8.4
- **Framework:** Shopware 6.7 (Symfony 7.4, Doctrine DBAL 4.4.x)
- **Database:** MySQL 8+ / MariaDB 10.4+

---

# Phase 1: Database Migration

Create a new migration that ALTERs both extension tables to add the three columns and recreates triggers with the updated column set.

### [NEW FILE] `src/Migration/Migration1748764800AddHashMetaColumns.php`

```php
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

        // Recreate triggers with the new column set
        $triggerManager = new TriggerManager($connection, new HashLogicService());
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
        // No destructive changes — columns are removed only on full uninstall
    }
}
```

**Notes:**
- Columns are added `AFTER fingerprint` to keep related columns together.
- `JSON` column type provides MySQL-native validation of JSON data.
- All three columns are `NULL`-able to allow backfill via the refresh command.
- Triggers are recreated immediately so new/updated addresses get metadata.
- Existing rows will have `NULL` values until `topdata:address-hashes:refresh` is run.

---

# Phase 2: Entity Definitions

Update both entity definitions to include the three new fields.

### [MODIFY] `src/Core/Content/AddressHash/CustomerAddressHashDefinition.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class CustomerAddressHashDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdah_customer_address_extension';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('address_id', 'addressId', CustomerAddressDefinition::class))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('fingerprint', 'fingerprint'))->addFlags(new ApiAware(), new Required()),
            new JsonField('hash_fields', 'hashFields'),
            new DateTimeField('hash_fields_changed_at', 'hashFieldsChangedAt'),
            new DateTimeField('hash_changed_at', 'hashChangedAt'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

### [MODIFY] `src/Core/Content/AddressHash/OrderAddressHashDefinition.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OrderAddressHashDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdah_order_address_extension';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('address_id', 'addressId', OrderAddressDefinition::class))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new ReferenceVersionField(OrderAddressDefinition::class, 'address_version_id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('fingerprint', 'fingerprint'))->addFlags(new ApiAware(), new Required()),
            new JsonField('hash_fields', 'hashFields'),
            new DateTimeField('hash_fields_changed_at', 'hashFieldsChangedAt'),
            new DateTimeField('hash_changed_at', 'hashChangedAt'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

---

# Phase 3: HashLogicService — Add `getHashFieldsJson()`

Add a method to `HashLogicService` that returns the current enabled fields as a sorted JSON string, suitable for embedding in SQL and storing in the `hash_fields` column.

### [MODIFY] `src/Service/HashLogicService.php`

Add the following method after `getEnabledFields()` (after line 59):

```php
    /**
     * Returns the JSON-encoded sorted list of enabled fields.
     * Used to store in the hash_fields column so each row records
     * which fields were used for its fingerprint.
     *
     * @return string JSON array of enabled field names, e.g. '["city","countryId","lastName","street","zipcode"]'
     */
    public function getHashFieldsJson(): string
    {
        $fields = $this->getEnabledFields();
        sort($fields);

        return json_encode($fields, JSON_THROW_ON_ERROR);
    }
```

---

# Phase 4: TriggerManager — Embed Metadata in Trigger SQL

Modify `TriggerManager` to accept and embed the `hash_fields` JSON and `hash_fields_changed_at` timestamp into the trigger SQL. The `hash_changed_at` column uses `NOW(3)` since it records when the hash was actually computed.

### [MODIFY] `src/Service/TriggerManager.php`

Replace the entire file content with:

```php
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

    /**
     * Updates all triggers for both customer and order address tables.
     *
     * @param string|null $hashFieldsChangedAt ISO-8601/datetime timestamp of when the
     *   field configuration was last changed. If null, the trigger will store NULL.
     */
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

    /**
     * Sets up INSERT and UPDATE triggers for a specific address table.
     *
     * @param string $coreTable The name of the core address table
     * @param string $extensionTable The name of the extension table
     * @param string $hashFieldsJson JSON-encoded array of enabled field names
     * @param string|null $hashFieldsChangedAt ISO-8601/datetime timestamp or null
     */
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

        // ---- Build column/value lists ----
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

        // ---- Create the INSERT trigger ----
        $this->connection->executeStatement(
            "CREATE TRIGGER `{$triggerIns}` AFTER INSERT ON `{$coreTable}` FOR EACH ROW REPLACE INTO `{$extensionTable}` {$replaceColumns} VALUES ({$replaceValues});"
        );

        // ---- Create the UPDATE trigger ----
        $this->connection->executeStatement(
            "CREATE TRIGGER `{$triggerUpd}` AFTER UPDATE ON `{$coreTable}` FOR EACH ROW REPLACE INTO `{$extensionTable}` {$replaceColumns} {$replaceSelect};"
        );
    }
}
```

**Key changes:**
- `updateAllTriggers()` now accepts an optional `$hashFieldsChangedAt` parameter
- `setupTriggersForTable()` receives the JSON field list and config-change timestamp
- `hash_fields` is embedded as a SQL string literal (properly escaped)
- `hash_fields_changed_at` is embedded as a SQL datetime literal (or `NULL`)
- `hash_changed_at` uses `NOW(3)` since it records when the hash was actually computed
- INSERT trigger uses `NOW(3)` for both `hash_changed_at` and `created_at`
- UPDATE trigger uses `NOW(3)` for `hash_changed_at` and preserves `created_at` via `IFNULL`

---

# Phase 5: ConfigChangeSubscriber — Pass Timestamp to TriggerManager

Update `ConfigChangeSubscriber` to capture the config change timestamp and pass it to `TriggerManager::updateAllTriggers()`.

### [MODIFY] `src/Subscriber/ConfigChangeSubscriber.php`

Replace the entire file content with:

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Subscriber;

use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class ConfigChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TriggerManager $triggerManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onConfigChange'];
    }

    public function onConfigChange(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== 'TopdataAddressHashesSW6.config.hashFields') {
            return;
        }

        $hashFieldsChangedAt = (new \DateTime())->format('Y-m-d H:i:s.v');
        $this->triggerManager->updateAllTriggers($hashFieldsChangedAt);
    }
}
```

**Key change:** The subscriber now captures a timestamp and passes it to `updateAllTriggers()`. This timestamp is embedded in the trigger SQL as a literal, so every address INSERT/UPDATE after the config change will record when the field configuration was last modified.

---

# Phase 6: Command_RefreshHashes — Include Metadata Columns

Update the refresh command to include the three new columns in the `REPLACE INTO` SQL.

### [MODIFY] `src/Command/Command_RefreshHashes.php`

Replace the entire file content with:

```php
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
```

**Key changes:**
- `hash_fields` is set to the JSON of the current field configuration (SQL literal)
- `hash_fields_changed_at` is set to `NOW(3)` — the time of the refresh (nearest proxy for "when the current config was established")
- `hash_changed_at` is set to `NOW(3)` — the time the hash was recomputed
- The SQL literal for `hash_fields` is escaped for single quotes

---

# Phase 7: API Controller — Expose Metadata

Update the `getConfig()` endpoint to include the `hashFieldsJson` in its response, giving API consumers the current field configuration in JSON format for comparison with stored `hash_fields`.

### [MODIFY] `src/Controller/AddressHashApiController.php`

Replace the entire file content with:

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Controller\AbstractTopdataApiController;

#[Route(defaults: ['_routeScope' => ['api']])]
class AddressHashApiController extends AbstractTopdataApiController
{
    public function __construct(private readonly HashLogicService $hashLogicService)
    {
    }

    #[Route(
        path: '/api/_action/topdata/address-hash-config',
        name: 'api.action.topdata.address-hash-config',
        methods: ['GET']
    )]
    public function getConfig(): JsonResponse
    {
        return $this->payloadResponse([
            'algorithm'      => 'SHA256',
            'normalization'  => 'lowercase, non-alphanumeric removed',
            'fields'         => $this->hashLogicService->getEnabledFields(),
            'fieldsJson'     => $this->hashLogicService->getHashFieldsJson(),
            'sqlTemplate'    => $this->hashLogicService->getSqlExpression('TABLE_ALIAS'),
        ]);
    }

    #[Route(
        path: '/api/_action/topdata/calculate-address-hash',
        name: 'api.action.topdata.calculate-address-hash',
        methods: ['POST']
    )]
    public function calculate(Request $request): JsonResponse
    {
        $data = [];

        if ($request->request->count() > 0) {
            $data = $request->request->all();
        } else {
            $decoded = json_decode((string)$request->getContent(), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($data === []) {
            return $this->errorResponse('No address data provided', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->hashLogicService->calculateHashDetailed($data);

        return $this->payloadResponse([
            'fingerprint'   => $result['hash'],
            'fieldsUsed'    => $result['used'],
            'fieldsIgnored' => $result['ignored'],
            'fieldsMissing' => $result['missing'],
            'config'        => [
                'enabledFields' => $this->hashLogicService->getEnabledFields(),
                'fieldsJson'    => $this->hashLogicService->getHashFieldsJson(),
            ],
        ]);
    }
}
```

**Key changes:**
- `getConfig()` now returns `fieldsJson` — the JSON-encoded sorted array of enabled fields
- `calculate()` now returns `fieldsJson` in the `config` object

This enables API consumers to compare stored `hash_fields` with the current config to detect stale hashes.

---

# Phase 8: Uninstall Cleanup

No changes needed. The existing `uninstall()` method in `TopdataAddressHashesSW6.php` drops both extension tables entirely, which implicitly removes the new columns. The destructive migration also handles cleanup.

---

# Phase 9: Documentation Update

Update the README to document the new columns and their purpose.

### [MODIFY] `README.md`

Add a section documenting the extension table schema including the new columns:

```markdown
## Extension Table Schema

Both `tdah_customer_address_extension` and `tdah_order_address_extension` contain:

| Column | Type | Description |
|---|---|---|
| `address_id` | BINARY(16) | FK to the address table (PK) |
| `fingerprint` | VARCHAR(64) | SHA-256 hash of the configured address fields |
| `hash_fields` | JSON | Sorted JSON array of field names used to compute the fingerprint |
| `hash_fields_changed_at` | DATETIME(3) | When the field configuration used for this fingerprint was saved |
| `hash_changed_at` | DATETIME(3) | When the fingerprint was last computed |
| `created_at` | DATETIME(3) | Row creation timestamp |
| `updated_at` | DATETIME(3) | Row update timestamp |
```

---

# Phase 10: Implementation Report

Write a report to `_ai/backlog/reports/260601_1627__IMPLEMENTATION_REPORT__address-hash-meta-columns.md` documenting what was done, files changed, and any deviations from the plan.

---

# Summary of All Files Changed

| File | Action | Description |
|---|---|---|
| `src/Migration/Migration1748764800AddHashMetaColumns.php` | **NEW** | Adds `hash_fields`, `hash_fields_changed_at`, `hash_changed_at` columns to both tables and recreates triggers |
| `src/Core/Content/AddressHash/CustomerAddressHashDefinition.php` | MODIFY | Add `JsonField` and two `DateTimeField` fields for new columns |
| `src/Core/Content/AddressHash/OrderAddressHashDefinition.php` | MODIFY | Add `JsonField` and two `DateTimeField` fields for new columns |
| `src/Service/HashLogicService.php` | MODIFY | Add `getHashFieldsJson()` method |
| `src/Service/TriggerManager.php` | MODIFY | Accept and embed metadata in trigger SQL; add `$hashFieldsChangedAt` param |
| `src/Subscriber/ConfigChangeSubscriber.php` | MODIFY | Capture config change timestamp and pass to TriggerManager |
| `src/Command/Command_RefreshHashes.php` | MODIFY | Include metadata columns in refresh SQL |
| `src/Controller/AddressHashApiController.php` | MODIFY | Expose `fieldsJson` in API responses |
| `README.md` | MODIFY | Document new columns |

---

# Backfill Strategy

After deploying this migration, existing rows will have `NULL` values for the three new columns. To backfill:

```bash
bin/console topdata:address-hashes:refresh
```

This recomputes all hashes and populates the metadata columns with the current configuration.

---

# Design Decisions

1. **`hash_fields` uses `JSON` column type** — MySQL validates that stored values are valid JSON, and the column can be queried with `JSON_CONTAINS` etc.
2. **`hash_fields` stored per-row** rather than globally — This makes each row self-contained and allows detecting stale hashes by comparing `hash_fields` with the current config without needing a separate config table.
3. **`hash_fields_changed_at` as literal in trigger SQL** — Since MySQL triggers cannot query `system_config`, the config-change timestamp is embedded as a literal when the trigger is recreated. This is safe because triggers ARE recreated on every config change.
4. **Sorted JSON array** — `getHashFieldsJson()` sorts the field names alphabetically to ensure consistent comparison regardless of config storage order.
5. **NULL-able columns** — All new columns allow `NULL` for backward compatibility with existing rows. The refresh command backfills them.
6. **No indexes on new columns** — The columns have low cardinality (`hash_fields` is the same for all rows between config changes) so indexes would not help. Add indexes later if query patterns warrant it.