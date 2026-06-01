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
- `src/Command/Command_RefreshHashes.php`: Slimmed implementation details and integrated standard `CliLogger` styling.
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
