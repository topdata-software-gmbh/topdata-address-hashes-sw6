---
filename: "_ai/backlog/active/250522_1000__IMPLEMENTATION_PLAN__dynamic_address_hashing.md"
title: "Dynamic and Configurable Address Hashing"
createdAt: 2025-05-22 10:00
updatedAt: 2025-05-22 10:00
status: draft
priority: high
tags: [shopware6, hashing, erp-integration, triggers]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement
Currently, the `topdata-address-hashes-sw6` plugin uses hardcoded SQL logic within migrations and triggers to calculate address fingerprints. This lacks flexibility if an ERP system requires different fields for de-duplication. Furthermore, the hashing logic is opaque to external systems, making it difficult for ERP middleware to predict or verify hashes without performing a full sync.

# Executive Summary
This plan transforms the plugin into a dynamic system where the hashing "recipe" is configurable via the Shopware Administration. We will:
1.  Introduce a multi-select configuration for address fields.
2.  Centralize hashing logic into a `HashLogicService` that generates both SQL expressions for triggers and PHP-based hashes for the API.
3.  Implement an Event Subscriber to automatically recreate database triggers whenever the configuration changes.
4.  Expose a "Recipe" API endpoint to describe the current hashing logic.
5.  Expose a "Hash Calculator" API endpoint for on-the-fly verification of address data.
6.  Utilize `TopdataFoundationSW6` for standardized configuration handling and API responses.

# Project Environment
- **Framework**: Shopware 6.7.*
- **Plugin Dependencies**: `topdata-foundation-sw6`
- **Database**: MySQL 8.0+ / MariaDB 10.11+ (requires `REGEXP_REPLACE` and `SHA2`)
- **PHP**: 8.2+

# Implementation Plan

## Phase 1: Configuration and Foundation
Add the configuration options and register the plugin with the Topdata Foundation registry.

### [MODIFY] `src/Resources/config/config.xml`
Add the multi-select field for hashable components.
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Hashing Configuration</title>
        <title lang="de-DE">Hashing-Konfiguration</title>
        
        <input-field type="multi-select">
            <name>hashFields</name>
            <label>Fields to include in Fingerprint</label>
            <label lang="de-DE">Felder für den Fingerabdruck</label>
            <options>
                <option><id>street</id><name>Street</name></option>
                <option><id>zipcode</id><name>Zipcode</name></option>
                <option><id>city</id><name>City</name></option>
                <option><id>phoneNumber</id><name>Phone Number</name></option>
                <option><id>additionalAddressLine1</id><name>Additional Line 1</name></option>
                <option><id>additionalAddressLine2</id><name>Additional Line 2</name></option>
                <option><id>company</id><name>Company</name></option>
                <option><id>department</id><name>Department</name></option>
                <option><id>salutationId</id><name>Salutation (ID)</name></option>
                <option><id>firstName</id><name>First Name</name></option>
                <option><id>lastName</id><name>Last Name</name></option>
                <option><id>title</id><name>Title</name></option>
                <option><id>countryId</id><name>Country (ID)</name></option>
            </options>
            <default-value>["street", "zipcode", "city", "lastName", "countryId"]</default-value>
        </input-field>
    </card>
</config>
```

### [MODIFY] `src/Resources/config/services.xml`
Register the plugin with Foundation's `TopConfigRegistry` via compiler pass logic and define new services.
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <!-- Logic Services -->
        <service id="Topdata\TopdataAddressHashesSW6\Service\HashLogicService" autowire="true" public="true" />
        <service id="Topdata\TopdataAddressHashesSW6\Service\TriggerManager" autowire="true" public="true" />

        <!-- Definitions and Extensions -->
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

        <!-- Command -->
        <service id="Topdata\TopdataAddressHashesSW6\Command\Command_RefreshHashes">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <argument type="service" id="Topdata\TopdataAddressHashesSW6\Service\HashLogicService"/>
            <tag name="console.command"/>
        </service>

        <!-- API Controllers -->
        <service id="Topdata\TopdataAddressHashesSW6\Controller\AddressHashApiController" autowire="true" public="true">
            <tag name="controller.service_arguments"/>
        </service>

        <!-- Subscribers -->
        <service id="Topdata\TopdataAddressHashesSW6\Subscriber\ConfigChangeSubscriber">
            <argument type="service" id="Topdata\TopdataAddressHashesSW6\Service\TriggerManager"/>
            <tag name="kernel.event_subscriber"/>
        </service>
    </services>
</container>
```

## Phase 2: Hashing Logic and Trigger Management

### [NEW FILE] `src/Service/HashLogicService.php`
Centralizes the field mapping and provides SQL/PHP calculation methods.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Topdata\TopdataFoundationSW6\Service\TopConfigRegistry;

class HashLogicService
{
    private const FIELD_MAP = [
        'street' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.street, ''), '[^a-zA-Z0-9]', '')", 'api' => 'street'],
        'zipcode' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.zipcode, ''), '[^a-zA-Z0-9]', '')", 'api' => 'zipcode'],
        'city' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.city, ''), '[^a-zA-Z0-9]', '')", 'api' => 'city'],
        'phoneNumber' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.phone_number, ''), '[^a-zA-Z0-9]', '')", 'api' => 'phoneNumber'],
        'additionalAddressLine1' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.additional_address_line1, ''), '[^a-zA-Z0-9]', '')", 'api' => 'additionalAddressLine1'],
        'additionalAddressLine2' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.additional_address_line2, ''), '[^a-zA-Z0-9]', '')", 'api' => 'additionalAddressLine2'],
        'company' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.company, ''), '[^a-zA-Z0-9]', '')", 'api' => 'company'],
        'department' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.department, ''), '[^a-zA-Z0-9]', '')", 'api' => 'department'],
        'salutationId' => ['sql' => "IFNULL(HEX(%s.salutation_id), '')", 'api' => 'salutationId'],
        'firstName' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.first_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'firstName'],
        'lastName' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.last_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'lastName'],
        'title' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.title, ''), '[^a-zA-Z0-9]', '')", 'api' => 'title'],
        'countryId' => ['sql' => "IFNULL(HEX(%s.country_id), '')", 'api' => 'countryId'],
    ];

    public function __construct(private readonly TopConfigRegistry $topConfigRegistry) {}

    public function getEnabledFields(): array
    {
        try {
            return $this->topConfigRegistry->getTopConfig('TopdataAddressHashesSW6')->get('hashFields') ?? [];
        } catch (\Throwable) {
            return ['street', 'zipcode', 'city', 'lastName', 'countryId'];
        }
    }

    public function getSqlExpression(string $alias = 'NEW'): string
    {
        $fields = $this->getEnabledFields();
        $parts = [];
        foreach ($fields as $field) {
            if (isset(self::FIELD_MAP[$field])) {
                $parts[] = sprintf(self::FIELD_MAP[$field]['sql'], $alias);
            }
        }
        return "SHA2(LOWER(CONCAT(" . implode(', ', $parts) . ")), 256)";
    }

    public function calculateHash(array $data): string
    {
        $fields = $this->getEnabledFields();
        $concat = '';
        foreach ($fields as $field) {
            $val = $data[$field] ?? '';
            if (in_array($field, ['salutationId', 'countryId'])) {
                // Remove dashes from UUIDs if present to match HEX() behavior
                $val = str_replace('-', '', (string)$val);
            } else {
                // Normalize text: lowercase and alphanumeric only
                $val = preg_replace('/[^a-zA-Z0-9]/', '', (string)$val);
            }
            $concat .= strtolower($val);
        }
        return hash('sha256', $concat);
    }
}
```

### [NEW FILE] `src/Service/TriggerManager.php`
Manages the lifecycle of database triggers.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Doctrine\DBAL\Connection;

class TriggerManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HashLogicService $hashLogicService
    ) {}

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
```

### [NEW FILE] `src/Subscriber/ConfigChangeSubscriber.php`
Listens for configuration updates to refresh triggers.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Subscriber;

use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class ConfigChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TriggerManager $triggerManager) {}

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onConfigChange'];
    }

    public function onConfigChange(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() === 'TopdataAddressHashesSW6.config.hashFields') {
            $this->triggerManager->updateAllTriggers();
        }
    }
}
```

## Phase 3: API Controllers

### [NEW FILE] `src/Controller/AddressHashApiController.php`
Implements the external-facing API logic.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Controller\AbstractTopdataApiController;

#[Route(path: '/api/_action/topdata', defaults: ['_routeScope' => ['api']])]
class AddressHashApiController extends AbstractTopdataApiController
{
    public function __construct(private readonly HashLogicService $hashLogicService) {}

    #[Route(path: '/address-hash-config', name: 'api.action.topdata.address-hash-config', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        return $this->payloadResponse([
            'algorithm' => 'SHA256',
            'normalization' => 'lowercase, non-alphanumeric removed',
            'fields' => $this->hashLogicService->getEnabledFields(),
            'sql_template' => $this->hashLogicService->getSqlExpression('TABLE_ALIAS')
        ]);
    }

    #[Route(path: '/calculate-address-hash', name: 'api.action.topdata.calculate-address-hash', methods: ['POST'])]
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->request->all();
        
        if (empty($data)) {
            return $this->errorResponse('No address data provided');
        }

        $hash = $this->hashLogicService->calculateHash($data);

        return $this->payloadResponse([
            'fingerprint' => $hash,
            'input_received' => $data
        ]);
    }
}
```

## Phase 4: Refactoring and Clean-up

### [MODIFY] `src/Command/Command_RefreshHashes.php`
Update the command to use the service instead of hardcoded strings.
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;

#[AsCommand(name: 'topdata:address-hashes:refresh', description: 'Recalculates all address hashes')]
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
        $hashExpr = $this->hashLogicService->getSqlExpression('`table`'); // Note: service needs adjustment for non-prefixed table field access or use specific aliases
        
        $output->writeln('Refreshing customer_address hashes...');
        $this->refreshTable('customer_address', 'tdah_customer_address_extension');

        $output->writeln('Refreshing order_address hashes...');
        $this->refreshTable('order_address', 'tdah_order_address_extension', 'version_id');

        return Command::SUCCESS;
    }

    private function refreshTable(string $table, string $extensionTable, ?string $versionField = null): void
    {
        // Re-generate local expression to ensure table alias matches
        $hashExpr = $this->hashLogicService->getSqlExpression($table);
        
        $insertColumns = $versionField !== null
            ? '(address_id, address_version_id, fingerprint, created_at, updated_at)'
            : '(address_id, fingerprint, created_at, updated_at)';
        $selectColumns = $versionField !== null
            ? "id, $versionField, $hashExpr, NOW(3), NULL"
            : "id, $hashExpr, NOW(3), NULL";

        $this->connection->executeStatement("REPLACE INTO `$extensionTable` $insertColumns SELECT $selectColumns FROM `$table` ");
    }
}
```

### [MODIFY] `src/TopdataAddressHashesSW6.php`
Register the plugin in Foundation during boot.
```php
// ... inside the class
public function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new \Topdata\TopdataFoundationSW6\DependencyInjection\TopConfigRegistryCompilerPass(
        self::class,
        [
            'hashFields' => 'hashFields',
        ]
    ));
}
```

## Phase 5: Documentation & Reporting
1. Update `README.md` to explain the new API endpoints and configuration options.
2. Finalize the implementation report.

### [MODIFY] `README.md`
```markdown
## API Documentation

### Get Hashing Recipe
`GET /api/_action/topdata/address-hash-config`
Returns the current list of fields used for hashing and the SQL template.

### Calculate Hash (Dry Run)
`POST /api/_action/topdata/calculate-address-hash`
Send raw address fields (snakeCase or camelCase based on config) to get the resulting hash without saving to DB.
```

# Implementation Report
Write the final report to `_ai/backlog/reports/250522_1200__IMPLEMENTATION_REPORT__dynamic_address_hashing.md`.
```markdown
---
filename: "_ai/backlog/reports/250522_1200__IMPLEMENTATION_REPORT__dynamic_address_hashing.md"
title: "Report: Dynamic and Configurable Address Hashing"
createdAt: 2025-05-22 12:00
updatedAt: 2025-05-22 12:00
planFile: "_ai/backlog/active/250522_1000__IMPLEMENTATION_PLAN__dynamic_address_hashing.md"
project: "topdata-address-hashes-sw6"
status: completed
filesCreated: 5
filesModified: 5
filesDeleted: 0
tags: [hashing, triggers, dynamic-config, api]
documentType: IMPLEMENTATION_REPORT
---

