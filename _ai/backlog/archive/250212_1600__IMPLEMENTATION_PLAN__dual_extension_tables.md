---
filename: "_ai/backlog/active/250212_1600__IMPLEMENTATION_PLAN__dual_extension_tables.md"
title: "Refactor Address Hashes to Dual Entity Extension Tables"
createdAt: 2025-02-12 16:00
updatedAt: 2026-04-01 19:15
status: completed
priority: high
tags: [shopware, mysql-triggers, entity-extensions, erp-integration]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---
```

# Implementation Plan: Refactor Address Hashes to Dual Entity Extension Tables

## 1. Problem Statement
The ERP system (Selectline) requires a persistent fingerprint for addresses to handle de-duplication. To make these fingerprints native to the Shopware API and DAL without modifying core tables or using `custom_fields`, we need to implement two separate extension tables: one for `customer_address` and one for `order_address`. These tables must be automatically updated via MariaDB triggers to ensure data integrity even when Shopware's DAL is bypassed.

## 2. Executive Summary
- **Schema**: Create `tdah_customer_address_extension` and `tdah_order_address_extension`.
- **Automation**: Implement `BEFORE INSERT` and `BEFORE UPDATE` triggers (or `REPLACE INTO` logic) on core tables to calculate fingerprints.
- **DAL**: Register two `EntityDefinition` classes and two `EntityExtension` classes to map these tables as `OneToOne` associations.
- **API**: Users can fetch hashes via `associations[fingerprint][]`.

## 3. Project Environment
- **Framework**: Shopware 6.7.*
- **Database**: MariaDB 10.11+ / MySQL 8.0+
- **Plugin**: `TopdataAddressHashesSW6`

---

## 4. Implementation Phases

### Phase 1: Database Migration & Triggers
Create the two specific extension tables and set up the triggers for both address types.

#### [MODIFY] `src/Migration/Migration1716380000CreateAddressHashTable.php`
```php
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
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `$tableName` (
                `address_id` BINARY(16) NOT NULL,
                `address_version_id` BINARY(16) NOT NULL,
                `fingerprint` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`address_id`, `address_version_id`),
                INDEX `idx.fingerprint` (`fingerprint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function createTriggers(Connection $connection): void
    {
        $this->_setupTriggersForTable($connection, 'customer_address', 'tdah_customer_address_extension');
        $this->_setupTriggersForTable($connection, 'order_address', 'tdah_order_address_extension');
    }

    protected function _setupTriggersForTable(Connection $connection, string $coreTable, string $extTable): void
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

        $connection->executeStatement("
            CREATE TRIGGER `$triggerIns` AFTER INSERT ON `$coreTable`
            FOR EACH ROW
            REPLACE INTO `$extTable` (address_id, address_version_id, fingerprint, created_at, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3), NULL);
        ");

        $connection->executeStatement("
            CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$coreTable`
            FOR EACH ROW
            REPLACE INTO `$extTable` (address_id, address_version_id, fingerprint, created_at, updated_at)
            SELECT NEW.id, $versionExpr, $hashExpr, IFNULL(created_at, NOW(3)), NOW(3)
            FROM (SELECT 1) AS dummy
            LEFT JOIN `$extTable` ON address_id = NEW.id AND address_version_id = $versionExpr;
        ");
    }

    public function updateDestructive(Connection $connection): void {}
}
```

### Phase 2: DAL Definitions
Define the extension entities.

#### [NEW FILE] `src/Core/Content/AddressHash/CustomerAddressHashDefinition.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
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
            (new ReferenceVersionField(CustomerAddressDefinition::class, 'address_version_id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('fingerprint', 'fingerprint'))->addFlags(new ApiAware(), new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

#### [NEW FILE] `src/Core/Content/AddressHash/OrderAddressHashDefinition.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
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
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
```

### Phase 3: Entity Extensions

#### [NEW FILE] `src/Extension/CustomerAddressExtension.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Extension;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash\CustomerAddressHashDefinition;

class CustomerAddressExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'fingerprint',
                'id',
                'address_id',
                CustomerAddressHashDefinition::class,
                false
            ))->addFlags(new ApiAware())
        );
    }

    public function getDefinitionClass(): string
    {
        return CustomerAddressDefinition::class;
    }
}
```

#### [NEW FILE] `src/Extension/OrderAddressExtension.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Extension;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash\OrderAddressHashDefinition;

class OrderAddressExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'fingerprint',
                'id',
                'address_id',
                OrderAddressHashDefinition::class,
                false
            ))->addFlags(new ApiAware())
        );
    }

    public function getDefinitionClass(): string
    {
        return OrderAddressDefinition::class;
    }
}
```

### Phase 4: Service Registration

#### [MODIFY] `src/Resources/config/services.xml`
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
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

        <service id="Topdata\TopdataAddressHashesSW6\Command\RefreshHashesCommand">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

### Phase 5: Command Refactoring

#### [MODIFY] `src/Command/RefreshHashesCommand.php`
*Update the command to write into the two distinct extension tables.*

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'topdata:address-hashes:refresh',
    description: 'Recalculates all address hashes for existing entries'
)]
class RefreshHashesCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Refreshing hashes for customer_address...');
        $this->refreshTable('customer_address', "tdah_customer_address_extension", "UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')");

        $output->writeln('Refreshing hashes for order_address...');
        $this->refreshTable('order_address', "tdah_order_address_extension", 'version_id');

        $output->writeln('<info>Successfully refreshed all address hashes.</info>');
        return Command::SUCCESS;
    }

    private function refreshTable(string $table, string $extTable, string $versionField): void
    {
        $hashExpr = "SHA2(LOWER(CONCAT(
            REGEXP_REPLACE(IFNULL(street, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(zipcode, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(city, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(phone_number, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(additional_address_line1, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(additional_address_line2, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(company, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(department, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(salutation_id), ''),
            REGEXP_REPLACE(IFNULL(first_name, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(last_name, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(title, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(country_id), '')
        )), 256)";

        $this->connection->executeStatement(
            "REPLACE INTO `$extTable` (address_id, address_version_id, fingerprint, created_at, updated_at)
            SELECT id, $versionField, $hashExpr, NOW(3), NULL FROM `$table`"
        );
    }
}
```

### Phase 6: Clean Up

#### [DELETE] `src/Migration/Migration1760000000AddCreatedAtToAddressHashTable.php`
#### [DELETE] `src/Migration/Migration1760000001FixFingerprintCollation.php`

#### [MODIFY] `src/TopdataAddressHashesSW6.php`
*Update uninstall logic to drop both tables.*

```php
// ... inside uninstall method
$connection->executeStatement('DROP TABLE IF EXISTS `tdah_customer_address_extension`');
$connection->executeStatement('DROP TABLE IF EXISTS `tdah_order_address_extension`');
// ... also drop all 4 triggers
```

---

## 5. Usage & Testing

### API Request Example
Fetch a customer address with its fingerprint:
`GET /api/customer-address/UUID?associations[fingerprint][]`

Response body will include:
```json
{
  "data": {
    "id": "...",
    "extensions": {
      "fingerprint": {
        "fingerprint": "8da7...",
        "addressId": "..."
      }
    }
  }
}
```

## 6. Implementation Report
Write report to `_ai/backlog/reports/250212_1630__IMPLEMENTATION_REPORT__dual_extension_tables.md`.

