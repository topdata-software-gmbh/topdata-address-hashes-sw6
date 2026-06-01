---
filename: "_ai/backlog/active/260601_1852__IMPLEMENTATION_PLAN__async_hash_regeneration.md"
title: "Asynchronous Hashing with ISO/Technical Key Normalization"
createdAt: 2026-06-01 18:52
updatedAt: 2026-06-01 18:52
status: draft
priority: high
tags: [shopware, asynchronous, triggers, background-queue, localization]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---
# Implementation Plan

## 1. Problem Statement
The current implementation of `ConfigChangeSubscriber` drops and recreates database triggers whenever the plugin configuration is updated. However, if the configured `hashFields` have changed, existing fingerprint records in the database (`tdah_customer_address_extension` and `tdah_order_address_extension`) become stale. 

Additionally, the hash calculation currently relies on Shopware's database-specific UUIDs (`salutation_id` and `country_id`), which are translated to raw hex-compatible strings. This causes identical physical addresses to yield different fingerprints on different systems, staging environments, or multi-tenant database instances where database IDs differ. 

---

## 2. Executive Summary of the Solution
This plan refactors the fingerprint calculation and trigger systems to support environment-independent hashing and queue-safe recalculations:
1. **Environment-Independent Keys**: Salutations are resolved to their global `salutation_key` (e.g. `mr`, `mrs`) and country values to their 2-character country `iso` code (e.g. `DE`, `US`). This replaces transient UUID hex conversions with standard, universally compatible keys.
2. **Normalized Hashing Order**: To prevent configuration order mismatches (e.g., selecting `["street", "zipcode"]` vs `["zipcode", "street"]`), the enabled field array is automatically sorted alphabetically in all entry points.
3. **Refactoring Hash Regeneration**: The SQL-based hash recalculation logic is moved from the console command `Command_RefreshHashes` into a central method in `HashLogicService::refreshAllHashes()`.
4. **Actual Change Verification**: The `ConfigChangeSubscriber` only triggers updates and queues jobs when a true, sorted comparison detects an actual difference in the selected configuration fields.
5. **Asynchronous Execution via Queue**: A lightweight `RefreshHashesMessage` is dispatched to the background queue, allowing the `RefreshHashesHandler` to recalculate hashes without blocking the Admin UI or timing out the browser.
6. **Command Modernization**: The CLI command is adapted to use Topdata’s `CliLogger` standard.

---

## 3. Project Environment Details
- **Project Name:** SW6.7 Plugin (Topdata Address Hashes)
- **Backend Root:** `src`
- **PHP Version:** `8.2` / `8.3` / `8.4`

---

## 4. Implementation Phases & Code Modifications

### Phase 1: Database Logic & Environment-Independent Service Refactoring
We update the `FIELD_MAP` SQL generation queries to pull ISO codes and technical keys using subqueries inside the triggers. In PHP, if a 32-character hexadecimal UUID is passed, we resolve it to the database's technical equivalent.

#### [MODIFY] `src/Service/HashLogicService.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataFoundationSW6\Service\TopConfigRegistry;

/**
 * Service for calculating address hashes based on configurable fields.
 * This service normalizes address data and generates SHA-256 hashes for deduplication purposes.
 */
class HashLogicService
{
    private const DEFAULT_FIELDS = ['street', 'zipcode', 'city', 'lastName', 'countryId'];

    /**
     * FIELD_MAP values modified to fetch globally consistent, environment-independent keys 
     * instead of database-specific binary UUIDs.
     */
    private const FIELD_MAP = [
        'street'                 => ['sql' => "REGEXP_REPLACE(IFNULL(%s.street, ''), '[^a-zA-Z0-9]', '')", 'api' => 'street'],
        'zipcode'                => ['sql' => "REGEXP_REPLACE(IFNULL(%s.zipcode, ''), '[^a-zA-Z0-9]', '')", 'api' => 'zipcode'],
        'city'                   => ['sql' => "REGEXP_REPLACE(IFNULL(%s.city, ''), '[^a-zA-Z0-9]', '')", 'api' => 'city'],
        'phoneNumber'            => ['sql' => "REGEXP_REPLACE(IFNULL(%s.phone_number, ''), '[^a-zA-Z0-9]', '')", 'api' => 'phoneNumber'],
        'additionalAddressLine1' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.additional_address_line1, ''), '[^a-zA-Z0-9]', '')", 'api' => 'additionalAddressLine1'],
        'additionalAddressLine2' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.additional_address_line2, ''), '[^a-zA-Z0-9]', '')", 'api' => 'additionalAddressLine2'],
        'company'                => ['sql' => "REGEXP_REPLACE(IFNULL(%s.company, ''), '[^a-zA-Z0-9]', '')", 'api' => 'company'],
        'department'             => ['sql' => "REGEXP_REPLACE(IFNULL(%s.department, ''), '[^a-zA-Z0-9]', '')", 'api' => 'department'],
        'salutationId'           => ['sql' => "IFNULL((SELECT salutation_key FROM salutation WHERE id = %s.salutation_id), '')", 'api' => 'salutationId'],
        'firstName'              => ['sql' => "REGEXP_REPLACE(IFNULL(%s.first_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'firstName'],
        'lastName'               => ['sql' => "REGEXP_REPLACE(IFNULL(%s.last_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'lastName'],
        'title'                  => ['sql' => "REGEXP_REPLACE(IFNULL(%s.title, ''), '[^a-zA-Z0-9]', '')", 'api' => 'title'],
        'countryId'              => ['sql' => "IFNULL((SELECT iso FROM country WHERE id = %s.country_id), '')", 'api' => 'countryId'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?TopConfigRegistry $topConfigRegistry = null
    ) {
    }

    /**
     * Returns the list of enabled fields sorted alphabetically to maintain a fully 
     * deterministic, order-independent configuration.
     *
     * @return string[] List of enabled field names
     */
    public function getEnabledFields(): array
    {
        try {
            if ($this->topConfigRegistry === null) {
                $fields = self::DEFAULT_FIELDS;
            } else {
                $fields = $this->topConfigRegistry->getTopConfig('TopdataAddressHashesSW6')->get('hashFields');
                if (!\is_array($fields) || $fields === []) {
                    $fields = self::DEFAULT_FIELDS;
                }
            }

            $filtered = array_values(array_filter($fields, static fn($field): bool => \is_string($field) && isset(self::FIELD_MAP[$field])));
            sort($filtered);

            return $filtered;
        } catch (\Throwable) {
            $default = self::DEFAULT_FIELDS;
            sort($default);
            return $default;
        }
    }

    public function getHashFieldsJson(): string
    {
        return json_encode($this->getEnabledFields(), JSON_THROW_ON_ERROR);
    }

    /**
     * Generates SQL expression for calculating address hash in database.
     * Uses SHA-256 algorithm with concatenated normalized field values.
     *
     * @param string $alias SQL table alias to use in the expression
     * @return string SQL expression for hash calculation
     */
    public function getSqlExpression(string $alias = 'NEW'): string
    {
        $parts = [];
        foreach ($this->getEnabledFields() as $field) {
            $parts[] = sprintf(self::FIELD_MAP[$field]['sql'], $alias);
        }

        return 'SHA2(LOWER(CONCAT(' . implode(', ', $parts) . ')), 256)';
    }

    /**
     * Recalculates all address hashes for existing entries in both customer and order tables.
     */
    public function refreshAllHashes(): void
    {
        $hashFieldsJson = $this->getHashFieldsJson();
        $hashFieldsSqlLiteral = "'" . str_replace("'", "''", $hashFieldsJson) . "'";

        $this->refreshTable('customer_address', 'tdah_customer_address_extension', null, $hashFieldsSqlLiteral);
        $this->refreshTable('order_address', 'tdah_order_address_extension', 'version_id', $hashFieldsSqlLiteral);
    }

    private function refreshTable(string $table, string $extensionTable, ?string $versionField, string $hashFieldsSqlLiteral): void
    {
        $hashExpr = $this->getSqlExpression($table);

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

    /**
     * Calculates a hash for the given address data.
     * Uses only enabled fields and normalizes the values before hashing.
     *
     * @param array<string, mixed> $data Address data array
     * @return string SHA-256 hash of the address data
     */
    public function calculateHash(array $data): string
    {
        return $this->calculateHashDetailed($data)['hash'];
    }

    /**
     * Calculates a hash for the given address data with detailed information about the process.
     * Returns the hash along with information about used, ignored, and missing fields.
     *
     * @param array<string, mixed> $data Address data array
     * @return array{hash: string, used: array<string, array{original: mixed, normalized: string}>, ignored: array<string, mixed>, missing: string[]}
     *         Hash calculation result with detailed information
     */
    public function calculateHashDetailed(array $data): array
    {
        $enabledFields = $this->getEnabledFields();
        $used = [];
        $ignored = [];
        $missing = [];
        $concat = '';

        // Process input data and identify ignored fields
        foreach ($data as $key => $value) {
            $camelKey = $this->toCamelCase((string)$key);
            if (!in_array($camelKey, $enabledFields, true) && !in_array((string)$key, $enabledFields, true)) {
                $ignored[(string)$key] = $value;
            }
        }

        // Process enabled fields and prepare for hashing
        foreach ($enabledFields as $field) {
            $value = $this->resolveInputValue($data, $field);
            $wasProvided = $this->wasFieldProvided($data, $field);

            if (!$wasProvided) {
                $missing[] = $field;
            }

            $originalValue = $value;

            // Resolve raw database UUIDs into their corresponding environment-independent technical keys
            if (\in_array($field, ['salutationId', 'countryId'], true)) {
                $rawHex = str_replace('-', '', (string)$value);
                if (strlen($rawHex) === 32 && ctype_xdigit($rawHex)) {
                    if ($field === 'countryId') {
                        $dbVal = $this->connection->fetchOne(
                            'SELECT iso FROM country WHERE id = UNHEX(:id)',
                            ['id' => $rawHex]
                        );
                    } else {
                        $dbVal = $this->connection->fetchOne(
                            'SELECT salutation_key FROM salutation WHERE id = UNHEX(:id)',
                            ['id' => $rawHex]
                        );
                    }
                    $value = $dbVal ?: '';
                } else {
                    $value = (string)$originalValue;
                }
            } else {
                $value = preg_replace('/[^a-zA-Z0-9]/', '', (string)$value) ?? '';
            }

            $normalizedValue = strtolower((string)$value);
            $concat .= $normalizedValue;

            $used[$field] = [
                'original'   => $originalValue,
                'normalized' => $normalizedValue,
            ];
        }

        return [
            'hash'    => hash('sha256', $concat),
            'used'    => $used,
            'ignored' => $ignored,
            'missing' => $missing,
        ];
    }

    private function wasFieldProvided(array $data, string $field): bool
    {
        if (array_key_exists($field, $data)) {
            return true;
        }

        $snakeCaseField = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        if (array_key_exists($snakeCaseField, $data)) {
            return true;
        }

        return false;
    }

    private function resolveInputValue(array $data, string $field): mixed
    {
        if (array_key_exists($field, $data)) {
            return $data[$field];
        }

        $snakeCaseField = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        if (array_key_exists($snakeCaseField, $data)) {
            return $data[$snakeCaseField];
        }

        return '';
    }

    private function toCamelCase(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}
```

---

### Phase 2: Symfony Messenger Message & Handler Creation
We introduce the background queue capabilities.

#### [NEW FILE] `src/Message/RefreshHashesMessage.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Message;

/**
 * Message payload dispatched when a background recalculation of all address hashes is requested.
 */
class RefreshHashesMessage
{
}
```

#### [NEW FILE] `src/Message/RefreshHashesHandler.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Message;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;

/**
 * Background consumer that processes address updates without blocking the browser.
 */
#[AsMessageHandler]
class RefreshHashesHandler
{
    public function __construct(
        private readonly HashLogicService $hashLogicService
    ) {
    }

    public function __invoke(RefreshHashesMessage $message): void
    {
        $this->hashLogicService->refreshAllHashes();
    }
}
```

---

### Phase 3: Config Change Subscriber Refactoring
We update `ConfigChangeSubscriber` to verify if a config change strictly differs before recreating triggers and dispatching a recalculation message.

#### [MODIFY] `src/Subscriber/ConfigChangeSubscriber.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Topdata\TopdataAddressHashesSW6\Message\RefreshHashesMessage;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class ConfigChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TriggerManager $triggerManager,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus
    ) {
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

        $newFields = $event->getValue();
        if (!\is_array($newFields)) {
            return;
        }

        // Sort new fields alphabetically to prevent configuration order-based mismatches
        sort($newFields);
        $newFieldsJson = json_encode($newFields, JSON_THROW_ON_ERROR);

        // Retrieve existing database-stored metadata configurations to prevent redundant processing
        $existingHashFieldsJson = $this->connection->fetchOne(
            'SELECT hash_fields FROM tdah_customer_address_extension WHERE hash_fields IS NOT NULL LIMIT 1'
        );

        // Skip execution entirely if the fields selected are logically equivalent
        if ($existingHashFieldsJson === $newFieldsJson) {
            return;
        }

        $hashFieldsChangedAt = (new \DateTime())->format('Y-m-d H:i:s.v');
        $this->triggerManager->updateAllTriggers($hashFieldsChangedAt);

        // Schedule recalculation in background queue
        $this->messageBus->dispatch(new RefreshHashesMessage());
    }
}
```

---

### Phase 4: Console Command Modernization
We simplify the console command to call `refreshAllHashes()` on the refactored service, inherit from `TopdataFoundationSW6`, and style all outputs through `CliLogger`.

#### [MODIFY] `src/Command/Command_RefreshHashes.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:address-hashes:refresh',
    description: 'Recalculates all address hashes for existing entries'
)]
class Command_RefreshHashes extends \Topdata\TopdataFoundationSW6\TopdataFoundationSW6
{
    public function __construct(
        private readonly HashLogicService $hashLogicService
    ) {
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        CliLogger::setCliStyle(new SymfonyStyle($input, $output));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::info('Refreshing hashes for customer_address and order_address...');

        try {
            $this->hashLogicService->refreshAllHashes();
            CliLogger::success('Successfully refreshed all address hashes.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            CliLogger::error('An error occurred while refreshing hashes: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

---

### Phase 5: Dependency Injection & Services Configuration
We update `services.xml` to inject the database connections and auto-register our newly created message handler.

#### [MODIFY] `src/Resources/config/services.xml`
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="Topdata\TopdataAddressHashesSW6\Service\HashLogicService" autowire="true" public="true"/>
        <service id="Topdata\TopdataAddressHashesSW6\Service\TriggerManager" autowire="true" public="true"/>

        <service id="Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash\CustomerAddressHashDefinition">
            <tag name="shopware.entity.definition" entity="tdah_customer_address_extension"/>
        </service>

        <service id="Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash\OrderAddressHashDefinition">
            <tag name="shopware.entity.definition" entity="tdah_order_address_extension"/>
        </service>

        <service id="Topdata\TopdataAddressHashesSW6\Extension\CustomerAddressExtension">
            <tag name="shopware.entity.extension"/>
        </service>

        <service id="Topdata\TopdataAddressHashesSW6\Extension\OrderAddressExtension">
            <tag name="shopware.entity.extension"/>
        </service>

        <service id="Topdata\TopdataAddressHashesSW6\Command\Command_RefreshHashes">
            <argument type="service" id="Topdata\TopdataAddressHashesSW6\Service\HashLogicService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataAddressHashesSW6\Subscriber\ConfigChangeSubscriber">
            <argument type="service" id="Topdata\TopdataAddressHashesSW6\Service\TriggerManager"/>
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <argument type="service" id="messenger.default_bus"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Message Handler auto-registration via tag -->
        <service id="Topdata\TopdataAddressHashesSW6\Message\RefreshHashesHandler" autowire="true">
            <tag name="messenger.message_handler"/>
        </service>

        <!-- CONTROLLERS -->
        <service id="Topdata\TopdataAddressHashesSW6\Controller\AddressHashApiController" autowire="true" public="true">
            <tag name="controller.service_arguments"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

    </services>
</container>
```

---

## 5. Documentation Update
The `README.md` should be updated to note that hashes are calculated using stable country ISO-2 codes and technical salutation keys instead of database IDs.

#### [MODIFY] `README.md`
```markdown
### Environment-Independent Fingerprints

To ensure fingerprints are 100% stable across staging systems, migration targets, and multi-tenant instances:
- **Country Fields** are evaluated using their global 2-character ISO codes (e.g., `DE`, `US`), rather than the environment's auto-generated `country_id`.
- **Salutations** are evaluated using their global `salutation_key` (e.g., `mr`, `mrs`, `company`), rather than the transient `salutation_id`.

Additionally, hashes are processed in alphabetical order regardless of selection order to maintain complete configuration determinism.

### Background Queue Processing

When you change the configuration under `hashFields`, the MySQL triggers are updated instantly, and a background task (`RefreshHashesMessage`) is queued.

Ensure that your background worker queue consumer is running:
```bash
bin/console messenger:consume
```
```

---

## 6. Implementation Report Generation
After implementation, the agent must generate an implementation report at the specified destination path.

#### [NEW FILE] `_ai/backlog/reports/260601_1852__IMPLEMENTATION_REPORT__async_hash_regeneration.md`
```markdown
---
filename: "_ai/backlog/reports/260601_1852__IMPLEMENTATION_REPORT__async_hash_regeneration.md"
title: "Report: Asynchronous Address Hashing with ISO/Technical Key Normalization"
createdAt: 2026-06-01 18:55
updatedAt: 2026-06-01 18:55
planFile: "_ai/backlog/active/260601_1852__IMPLEMENTATION_PLAN__async_hash_regeneration.md"
project: "Topdata Address Hashes SW6"
status: completed
filesCreated: 2
filesModified: 5
filesDeleted: 0
tags: [shopware, asynchronous, triggers, background-queue, localization]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The hash regeneration mechanism has been safely moved from the console command layer to `HashLogicService::refreshAllHashes()`. System configuration shifts are processed asynchronously via the background queue if actual changes are detected. Furthermore, the generation algorithms now utilize standardized ISO codes and salutation keys, ensuring consistent fingerprint outputs across distinct environments.

## 2. Files Changed
### Created Files
- `src/Message/RefreshHashesMessage.php`: Simple transfer object for queue dispatching.
- `src/Message/RefreshHashesHandler.php`: Async queue handler invoking calculations on background workers.

### Modified Files
- `src/Service/HashLogicService.php`: Consolidated DBAL-based query building, added technical lookup resolving for UUID inputs, and enforced alphabetical field ordering.
- `src/Subscriber/ConfigChangeSubscriber.php`: Normalized incoming field lists, checked existing DB records to bypass redundant processing, and dispatched background messages.
- `src/Command/Command_RefreshHashes.php`: Slimmed implementation details, inherited foundation base commands, and integrated standard `CliLogger` styling.
- `src/Resources/config/services.xml`: Adjusted DI arguments and registered messenger subscribers.
- `README.md`: Included technical information on the normalized hashing algorithms and instructions for the queue worker.

## 3. Key Changes
- Migrated calculations to execute in a central service block.
- Implemented alphabetical sorting on enabled hashing fields to remove selection-order discrepancies.
- Substituted transient shop-specific Hex IDs in triggers and APIs with cross-compatible country `iso` codes and technical `salutation_keys`.
- Added DB verification checks to bypass trigger rebuilds and queue dispatches if the configuration remains unchanged.

## 4. Deviations from Plan
*None.*

## 5. Technical Decisions
- DB check bypasses cache systems using a direct SQL subquery to guarantee absolute configuration accuracy on dynamic trigger rebuild checks.
- API calculation inputs handle BOTH raw UUIDs (looked up dynamically via injected DB connection) and technical ISO codes/salutation keys directly.

## 6. Testing Notes
- Modify selection under Hashing Configuration in Admin UI.
- Verify that a `RefreshHashesMessage` task is visible in database `messenger_messages` if field selection changed.
- Verify that no tasks are dispatched if configuration fields are saved with identical selections.
- Run `bin/console messenger:consume` and confirm hashes rebuild safely.
- Run `bin/console topdata:address-hashes:refresh` to verify manual calculation remains functional.
```

