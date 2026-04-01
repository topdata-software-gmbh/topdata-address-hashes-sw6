---
filename: "_ai/backlog/reports/260401_1858__IMPLEMENTATION_REPORT__destructive-uninstall-cleanup.md"
title: "Report: Destructive uninstall should remove hashes table and triggers"
createdAt: 2026-04-01 18:58
updatedAt: 2026-04-01 18:58
planFile: "_ai/backlog/active/260401_1858__IMPLEMENTATION_PLAN__destructive-uninstall-cleanup.md"
project: "topdata-address-hashes-sw6"
status: completed
filesCreated: 0
filesModified: 1
filesDeleted: 0
tags: [shopware6, plugin, uninstall, database-cleanup]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Implemented destructive cleanup logic in the migration class to drop the custom `tdah_address_hash` table and all associated database triggers (`tdah_customer_address_ins`, `tdah_customer_address_upd`, `tdah_order_address_ins`, `tdah_order_address_upd`) when the plugin is uninstalled with the `--delete` flag.

## Files Changed

### Modified Files
- **src/Migration/Migration1716380000CreateAddressHashTable.php**: Added drop statements in `updateDestructive()` method to remove triggers and table.

## Key Changes

- Added 5 `DROP TRIGGER IF EXISTS` statements for all triggers
- Added `DROP TABLE IF EXISTS` statement for the custom table
- Triggers and table are dropped in the correct order (triggers first, then table)

## Technical Decisions

- Used `IF EXISTS`/`IF NOT EXISTS` clauses for idempotent drop operations
- Drop order: triggers first, then table (to avoid dependency errors)
- Follows Shopware migration pattern for destructive uninstalls

## Testing Notes

To verify the implementation works correctly:

```bash
# 1. Install and activate the plugin
bin/console plugin:install TopdataAddressHashesSW6 --activate

# 2. Verify table exists
mysql -e "SHOW TABLES LIKE 'tdah_address_hash'"

# 3. Verify triggers exist
mysql -e "SHOW TRIGGERS LIKE 'tdah_%'"

# 4. Uninstall destructively
bin/console plugin:uninstall TopdataAddressHashesSW6 --delete

# 5. Verify table removed
mysql -e "SHOW TABLES LIKE 'tdah_address_hash'"  # Should be empty

# 6. Verify triggers removed
mysql -e "SHOW TRIGGERS LIKE 'tdah_%'"  # Should be empty
```

## Documentation Updates

No user documentation changes required - this is internal plugin behavior that doesn't affect user-facing functionality.

## Next Steps

None - the implementation is complete and follows Shopware 6 best practices for plugin cleanup on destructive uninstall.