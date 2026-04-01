---
filename: "_ai/backlog/active/260401_1858__IMPLEMENTATION_PLAN__destructive-uninstall-cleanup.md"
title: "Destructive uninstall should remove hashes table and triggers"
createdAt: 2026-04-01 18:58
updatedAt: 2026-04-01 18:58
status: completed
priority: medium
tags: [shopware6, plugin, uninstall, database-cleanup]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## Problem Description

When uninstalling the plugin destructively (e.g., via `bin/console plugin:uninstall --delete TopdataAddressHashesSW6`), the custom table `tdah_address_hash` and the database triggers (`tdah_customer_address_ins`, `tdah_customer_address_upd`, `tdah_order_address_ins`, `tdah_order_address_upd`) are not removed from the database. This leaves orphaned database objects that should be cleaned up during a destructive uninstall.

## Executive Summary

Add destructive cleanup logic to the migration class to drop the custom table and all associated triggers when the plugin is uninstalled destructively. The Shopware migration system calls the `updateDestructive()` method during uninstallation with the `--delete` flag.

## Project Environment

- **Project**: topdata-address-hashes-sw6 (Shopware 6 plugin)
- **Language**: PHP 8.1+
- **Framework**: Shopware 6.7
- **Database**: MySQL/MariaDB (container: focus-mariadb)
- **Application**: Shopware container (container: focus-www)
- **Table to drop**: `tdah_address_hash`
- **Triggers to drop**: `tdah_customer_address_ins`, `tdah_customer_address_upd`, `tdah_order_address_ins`, `tdah_order_address_upd`

---

## Implementation Phases

### Phase 1: Modify Migration File

**File**: `src/Migration/Migration1716380000CreateAddressHashTable.php`

Add drop statements in the `updateDestructive()` method to remove the table and triggers.

[MODIFY] `src/Migration/Migration1716380000CreateAddressHashTable.php`:

```php
public function updateDestructive(Connection $connection): void
{
    $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_customer_address_ins`");
    $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_customer_address_upd`");
    $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_order_address_ins`");
    $connection->executeStatement("DROP TRIGGER IF EXISTS `tdah_order_address_upd`");
    $connection->executeStatement("DROP TABLE IF EXISTS `tdah_address_hash`");
}
```

---

## Verification

After implementing, verify the destructive uninstall works:

1. Enter the Shopware container: `docker exec -it focus-www bash`
2. Install the plugin: `bin/console plugin:install TopdataAddressHashesSW6 --activate`
3. Check table exists (from host or mariadb container):
   ```bash
   docker exec -it focus-mariadb mysql -u root -p Shopware -e "SHOW TABLES LIKE 'tdah_address_hash'"
   ```
4. Check triggers exist:
   ```bash
   docker exec -it focus-mariadb mysql -u root -p Shopware -e "SHOW TRIGGERS LIKE 'tdah_%'"
   ```
5. Uninstall destructively: `bin/console plugin:uninstall TopdataAddressHashesSW6 --delete`
6. Verify table removed:
   ```bash
   docker exec -it focus-mariadb mysql -u root -p Shopware -e "SHOW TABLES LIKE 'tdah_address_hash'"
   ```
   (should be empty)
7. Verify triggers removed:
   ```bash
   docker exec -it focus-mariadb mysql -u root -p Shopware -e "SHOW TRIGGERS LIKE 'tdah_%'"
   ```
   (should be empty)