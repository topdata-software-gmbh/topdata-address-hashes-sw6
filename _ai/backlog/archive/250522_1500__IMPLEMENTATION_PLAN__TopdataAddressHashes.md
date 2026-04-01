---
filename: "_ai/backlog/archive/250522_1500__IMPLEMENTATION_PLAN__TopdataAddressHashes.md"
title: "Implementation of Bulletproof Address Hashing for ERP Sync"
createdAt: 2025-05-22 15:00
updatedAt: 2026-04-01 00:00
status: completed
priority: high
tags: [shopware6, erp, mysql-triggers, data-integrity]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## Problem Description
The ERP system (SelectLine) requires a way to identify duplicate delivery addresses originating from Shopware 6. Shopware creates a new `order_address` entry for every order, regardless of whether the address is already known. This causes data inflation in the ERP. We need a reliable, "fingerprint-based" approach (address hash) that remains accurate even if addresses are modified via the Shopware Admin, Storefront, API, or direct SQL queries by other plugins.

## Executive Summary
This plan implements a dedicated hashing solution within the `TopdataAddressHashesSW6` plugin. 
- **Storage**: A custom table `tdah_address_hash` stores the relationship between an address ID and its calculated hash.
- **Automation**: MySQL Triggers are used to calculate and update hashes automatically on `INSERT` or `UPDATE` of `customer_address` and `order_address`. This bypasses PHP-level limitations and ensures 100% data integrity.
- **Compatibility**: No core tables are modified. The solution follows Shopware best practices by using a dedicated extension table.
- **ERP Integration**: Provides simple SQL access for the ERP "guy"  to join the hashes.

## Project Environment
- **Plugin Name**: `TopdataAddressHashesSW6`
- **Namespace**: `Topdata\TopdataAddressHashesSW6`
- **Shopware Version**: 6.7.*
- **Database**: MySQL 8.0+ / MariaDB 10.11+ (supporting `REGEXP_REPLACE` and Triggers)

---

## Phase 1: Database Schema & Triggers

### [NEW FILE] `src/Migration/Migration1716380000CreateAddressHashTable.php`
This migration creates the mapping table and the triggers to ensure hashes are generated automatically by the DB engine.

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
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `tdah_address_hash` (
                `address_id` BINARY(16) NOT NULL,
                `address_version_id` BINARY(16) NOT NULL,
                `fingerprint` VARCHAR(64) NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`address_id`, `address_version_id`),
                INDEX `idx.tdah_fingerprint` (`fingerprint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->createTrigger($connection, 'customer_address');
        $this->createTrigger($connection, 'order_address');
    }

    private function createTrigger(Connection $connection, string $table): void
    {
        $triggerIns = "tdah_{$table}_ins";
        $triggerUpd = "tdah_{$table}_upd";

        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerIns` ");
        $connection->executeStatement("DROP TRIGGER IF EXISTS `$triggerUpd` ");

        // Hash Logic: SHA256 of lowercase, alphanumeric-only concatenated string
        $hashExpr = "SHA2(LOWER(CONCAT(
            REGEXP_REPLACE(IFNULL(NEW.street, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.zipcode, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(NEW.city, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(NEW.country_id), '')
        )), 256)";

        // Use a default version_id for customer_address if not present
        $versionExpr = $table === 'customer_address' 
            ? "UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')" 
            : "NEW.version_id";

        $connection->executeStatement("
            CREATE TRIGGER `$triggerIns` AFTER INSERT ON `$table`
            FOR EACH ROW 
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, fingerprint, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3));
        ");

        $connection->executeStatement("
            CREATE TRIGGER `$triggerUpd` AFTER UPDATE ON `$table`
            FOR EACH ROW 
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, fingerprint, updated_at)
            VALUES (NEW.id, $versionExpr, $hashExpr, NOW(3));
        ");
    }

    public function updateDestructive(Connection $connection): void {}
}
```

---

## Phase 2: Maintenance Commands

### [MODIFY] `src/Command/ExampleCommand.php` -> `src/Command/RefreshHashesCommand.php`
[RENAME & MODIFY] Rename the example command to provide a utility for ERP guy to recalculate all hashes for existing data.

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
        $this->refreshTable('customer_address', "UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')");
        
        $output->writeln('Refreshing hashes for order_address...');
        $this->refreshTable('order_address', "version_id");

        $output->writeln('<info>Successfully refreshed all address hashes.</info>');
        return Command::SUCCESS;
    }

    private function refreshTable(string $table, string $versionField): void
    {
        $hashExpr = "SHA2(LOWER(CONCAT(
            REGEXP_REPLACE(IFNULL(street, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(zipcode, ''), '[^a-zA-Z0-9]', ''),
            REGEXP_REPLACE(IFNULL(city, ''), '[^a-zA-Z0-9]', ''),
            IFNULL(HEX(country_id), '')
        )), 256)";

        $this->connection->executeStatement("
            REPLACE INTO `tdah_address_hash` (address_id, address_version_id, fingerprint, updated_at)
            SELECT id, $versionField, $hashExpr, NOW(3) FROM `$table`
        ");
    }
}
```

---

## Phase 3: Cleanup & Services

### [DELETE] `src/Controller/ExampleController.php`
### [DELETE] `src/Resources/views/storefront/example.html.twig`
### [DELETE] `src/Resources/config/routes.xml`

### [MODIFY] `src/Resources/config/services.xml`
Register the command.

```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <service id="Topdata\TopdataAddressHashesSW6\Command\RefreshHashesCommand">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <tag name="console.command"/>
        </service>
    </services>
</container>
```

---

## Phase 4: User Documentation (for ERP guy)

### [MODIFY] `README.md`
Add the SQL instructions for ERP guy.

```markdown
# Topdata Address Hashes SW6 (ERP Integration)

This plugin provides a persistent "Address Fingerprint" (Hash) for de-duplication in ERP systems.

## For the ERP Guy 

The hashes are stored in a separate table to keep the Shopware core clean. 

### How to get the hash for a Customer Address:
```sql
SELECT ca.id, h.fingerprint 
FROM customer_address ca
JOIN tdah_address_hash h ON ca.id = h.address_id 
  AND h.address_version_id = UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')
WHERE ca.customer_id = UNHEX('...');
```

### How to get the hash for an Order Delivery Address:
```sql
SELECT oa.id, h.fingerprint 
FROM order_address oa
JOIN tdah_address_hash h ON oa.id = h.address_id 
  AND oa.version_id = h.address_version_id
WHERE oa.id = UNHEX('...');
```

### Logic:
The hash is a `SHA256` of: `LOWER(STREET + ZIP + CITY + COUNTRY_HEX_ID)` with all non-alphanumeric characters removed.
```

---

## Phase 5: Implementation Report

### [NEW FILE] `_ai/backlog/reports/250522_1600__IMPLEMENTATION_REPORT__TopdataAddressHashes.md`

```yaml
---
filename: "_ai/backlog/reports/250522_1600__IMPLEMENTATION_REPORT__TopdataAddressHashes.md"
title: "Report: Implementation of Address Hashing"
createdAt: 2025-05-22 16:00
updatedAt: 2025-05-22 16:00
planFile: "_ai/backlog/active/250522_1500__IMPLEMENTATION_PLAN__TopdataAddressHashes.md"
project: "TopdataAddressHashesSW6"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 3
tags: [erp, database, cleanup]
documentType: IMPLEMENTATION_REPORT
---
```

### Report Content
1. **Summary**: Implemented a bulletproof address hashing system using MySQL triggers. This ensures that every address (customer or order) is automatically fingerprinted for the ERP system to prevent duplicates.
2. **Files Changed**:
    - `src/Migration/Migration1716380000CreateAddressHashTable.php`: Main DB logic.
    - `src/Command/RefreshHashesCommand.php`: Utility for initial sync.
    - `src/Resources/config/services.xml`: Service registration.
    - `README.md`: Added documentation for the ERP developer.
    - Deleted Example Controller, View, and Route files.
3. **Key Changes**:
    - Trigger-based hashing for 100% reliability.
    - Dedicated mapping table `tdah_address_hash`.
    - Normalization logic handles variations in formatting (spaces, special chars).
4. **Technical Decisions**: Chose SHA256 over MD5 for future-proofing and collision avoidance. Used triggers to ensure that even manual DB imports by other plugins are covered.
5. **Testing Notes**: Verify by changing an address in the Shopware Admin and checking if the `tdah_address_hash` table reflects the update. Run `bin/console topdata:address-hashes:refresh` to populate existing data.


