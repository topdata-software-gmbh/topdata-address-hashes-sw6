---
filename: "_ai/backlog/reports/260401_1915__IMPLEMENTATION_REPORT__dual_extension_tables.md"
title: "Report: Refactor address hashes to dual entity extension tables"
createdAt: 2026-04-01 19:15
updatedAt: 2026-04-01 19:15
planFile: "_ai/backlog/archive/250212_1600__IMPLEMENTATION_PLAN__dual_extension_tables.md"
project: "topdata-address-hashes-sw6"
status: completed
filesCreated: 4
filesModified: 6
filesDeleted: 2
tags: [shopware6, dal, migration, triggers, erp-integration]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Implemented the full dual-table refactor for address fingerprints by replacing the single `tdah_address_hash` table with `tdah_customer_address_extension` and `tdah_order_address_extension`, adding DAL entity definitions and entity extensions, wiring services, and updating command/uninstall/docs accordingly.

## Files Changed

### Created Files
- **src/Core/Content/AddressHash/CustomerAddressHashDefinition.php**: DAL definition for `tdah_customer_address_extension`.
- **src/Core/Content/AddressHash/OrderAddressHashDefinition.php**: DAL definition for `tdah_order_address_extension`.
- **src/Extension/CustomerAddressExtension.php**: One-to-one API-aware extension on `customer_address` as `fingerprint`.
- **src/Extension/OrderAddressExtension.php**: One-to-one API-aware extension on `order_address` as `fingerprint`.

### Modified Files
- **src/Migration/Migration1716380000CreateAddressHashTable.php**: Reworked migration to create two extension tables and two trigger pairs, and updated destructive cleanup.
- **src/Command/RefreshHashesCommand.php**: Refactored backfill command to write into both extension tables with explicit table mapping.
- **src/Resources/config/services.xml**: Registered two entity definitions and two entity extensions.
- **src/TopdataAddressHashesSW6.php**: Updated uninstall cleanup to drop both extension tables.
- **README.md**: Updated SQL examples to use the new extension tables.
- **_ai/backlog/archive/250212_1600__IMPLEMENTATION_PLAN__dual_extension_tables.md**: Marked plan as completed and archived.

### Deleted Files
- **src/Migration/Migration1760000000AddCreatedAtToAddressHashTable.php**: Removed obsolete migration for previous single-table approach.
- **src/Migration/Migration1760000001FixFingerprintCollation.php**: Removed obsolete migration for previous single-table approach.

## Key Changes

- Migration now creates:
  - `tdah_customer_address_extension`
  - `tdah_order_address_extension`
- Triggers are generated per core table (`customer_address`, `order_address`) and write to the corresponding extension table.
- Fingerprint hash logic remains unchanged and normalized exactly as before.
- `created_at` and `updated_at` behavior:
  - Insert trigger writes `created_at = NOW(3)`, `updated_at = NULL`.
  - Update trigger preserves existing `created_at` and writes `updated_at = NOW(3)`.
- DAL exposure is available through `fingerprint` one-to-one associations for both address entities.

## Validation

- Ran PHP lint for all PHP files in `src`:
  - `find src -name '*.php' -print0 | xargs -0 -n1 php -l`
  - Result: no syntax errors detected.

## Notes

- IDE type-resolution warnings for Shopware/Doctrine classes are environment/indexing related and not syntax/runtime failures in the plugin code.
