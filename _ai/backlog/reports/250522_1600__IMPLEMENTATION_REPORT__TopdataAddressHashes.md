---
filename: "_ai/backlog/reports/250522_1600__IMPLEMENTATION_REPORT__TopdataAddressHashes.md"
title: "Report: Implementation of Address Hashing"
createdAt: 2025-05-22 16:00
updatedAt: 2026-04-01 00:00
planFile: "_ai/backlog/archive/250522_1500__IMPLEMENTATION_PLAN__TopdataAddressHashes.md"
project: "TopdataAddressHashesSW6"
status: completed
filesCreated: 3
filesModified: 3
filesDeleted: 4
tags: [erp, database, cleanup]
documentType: IMPLEMENTATION_REPORT
---

## Summary
Implemented a bulletproof address hashing system using MySQL triggers. Every relevant address row (customer or order) is now automatically fingerprinted for ERP de-duplication.

## Files Changed
- `src/Migration/Migration1716380000CreateAddressHashTable.php`: Creates hash table and DB triggers.
- `src/Command/RefreshHashesCommand.php`: Adds utility command for backfilling hashes.
- `src/Resources/config/services.xml`: Registers refresh command in DI.
- `README.md`: Adds ERP-focused SQL usage docs and refresh command usage.
- Deleted obsolete example files from command/controller/routes/view scaffolding.

## Key Changes
- Trigger-based hashing on `INSERT` and `UPDATE` for `customer_address` and `order_address`.
- Dedicated mapping table `tdah_address_hash` with composite primary key and hash index.
- Normalization logic removes non-alphanumeric characters and lowercases before SHA256.
- Backfill command `bin/console topdata:address-hashes:refresh` for existing datasets.

## Technical Decisions
- Used SHA256 instead of MD5 for stronger collision resistance and future-proofing.
- Used DB triggers to cover all write paths, including admin, API, imports, and direct SQL updates.

## Testing Notes
- Run migrations and verify `tdah_address_hash` exists.
- Insert or update records in `customer_address` and `order_address`, then verify hash rows update automatically.
- Execute `bin/console topdata:address-hashes:refresh` and confirm existing rows are populated/replaced.
