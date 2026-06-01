---
filename: "_ai/backlog/reports/260601_1627__IMPLEMENTATION_REPORT__address-hash-meta-columns.md"
title: "Report: Add Hash Metadata Columns to Address Hash Extension Tables"
createdAt: 2026-06-01 16:27
updatedAt: 2026-06-01 16:27
planFile: "_ai/backlog/active/260601_1627__IMPLEMENTATION_PLAN__address-hash-meta-columns.md"
project: "topdata-address-hashes-sw6"
status: completed
filesCreated: 1
filesModified: 7
filesDeleted: 0
tags: [shopware, database, migration, hash, metadata]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Implemented all phases of the plan to add `hash_fields`, `hash_fields_changed_at`, and `hash_changed_at` columns to both `tdah_customer_address_extension` and `tdah_order_address_extension` tables, enabling programmatic detection of stale hashes.

## Files Changed

### Created Files
1. **src/Migration/Migration1748764800AddHashMetaColumns.php** — New migration that adds the three JSON/DATETIME columns to both extension tables and recreates triggers with metadata.

### Modified Files
1. **src/Core/Content/AddressHash/CustomerAddressHashDefinition.php** — Added `JsonField(hash_fields)`, `DateTimeField(hash_fields_changed_at)`, and `DateTimeField(hash_changed_at)` to the entity definition.
2. **src/Core/Content/AddressHash/OrderAddressHashDefinition.php** — Same three fields added to order address definition.
3. **src/Service/HashLogicService.php** — Added `getHashFieldsJson()` method that returns a sorted JSON array of currently enabled fields.
4. **src/Service/TriggerManager.php** — `updateAllTriggers()` now accepts optional `$hashFieldsChangedAt` param; trigger SQL embeds `hash_fields` and `hash_fields_changed_at` as literals and `hash_changed_at` as `NOW(3)`.
5. **src/Subscriber/ConfigChangeSubscriber.php** — Captures config change timestamp and passes it to `TriggerManager::updateAllTriggers()`.
6. **src/Command/Command_RefreshHashes.php** — Refresh SQL now includes all three metadata columns with current field config and `NOW(3)` timestamps.
7. **src/Controller/AddressHashApiController.php** — `getConfig()` and `calculate()` endpoints now expose `fieldsJson` for API consumers.
8. **README.md** — Added Extension Table Schema section, stale hash detection SQL example.

## New Columns

| Column | Type | Purpose |
|---|---|---|
| `hash_fields` | `JSON` NULL | Sorted JSON array of field names used to compute this hash |
| `hash_fields_changed_at` | `DATETIME(3)` NULL | When the field configuration used for this hash was saved |
| `hash_changed_at` | `DATETIME(3)` NULL | When the fingerprint was last computed |

## Verification

All modified files pass PHP syntax validation (`php -l`).

## Deviations from Plan

None. All phases implemented as specified.

## Backfill

After deployment, run `bin/console topdata:address-hashes:refresh` to populate metadata for existing rows.
