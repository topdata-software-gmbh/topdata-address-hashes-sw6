---
filename: "_ai/backlog/reports/260402_0109__IMPLEMENTATION_REPORT__enhance-address-hash-api-response.md"
title: "Report: Enhance address hash API response with field usage details"
createdAt: 2026-04-02 01:09
updatedAt: 2026-04-02 01:09
planFile: "_ai/backlog/archive/260402_0109__IMPLEMENTATION_PLAN__enhance-address-hash-api-response.md"
project: "topdata-address-hashes-sw6"
status: completed
filesCreated: 1
filesModified: 4
filesDeleted: 0
tags: [api, hash-calculation, visibility, diagnostics]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Implemented the full plan to enhance the address hash API response with visibility into how hashes are calculated, including detailed used field normalization, ignored input fields, and missing enabled fields.

## Files Changed

### Created Files
- **_ai/backlog/reports/260402_0109__IMPLEMENTATION_REPORT__enhance-address-hash-api-response.md**: Implementation report for this plan.

### Modified Files
- **src/Service/HashLogicService.php**: Added `calculateHashDetailed()` with `used`, `ignored`, and `missing` metadata; kept `calculateHash()` as a compatibility wrapper.
- **src/Controller/AddressHashApiController.php**: Switched hash endpoint to return detailed payload (`fingerprint`, `fields_used`, `fields_ignored`, `fields_missing`, `config.enabled_fields`).
- **README.md**: Updated API documentation and examples to the new response format, with ignored and missing field examples.
- **_ai/backlog/archive/260402_0109__IMPLEMENTATION_PLAN__enhance-address-hash-api-response.md**: Marked plan status as `completed` and archived it.

## New API Response Format

`POST /api/_action/topdata/calculate-address-hash` now returns:
- `fingerprint`: calculated SHA256 hash
- `fields_used`: per-enabled-field object with `original` and `normalized` values
- `fields_ignored`: input keys not part of enabled hash fields
- `fields_missing`: enabled fields not present in request input (resolved as empty)
- `config.enabled_fields`: current enabled fields from plugin config

## Verification

Executed PHP syntax validation:
- `php -l src/Service/HashLogicService.php` -> no syntax errors
- `php -l src/Controller/AddressHashApiController.php` -> no syntax errors

Runtime API curl verification was not executed here because no live Shopware base URL/token were available in this workspace context.

## Notes

- Hash calculation behavior remains unchanged for existing callers of `calculateHash()`.
- The detailed method is additive and improves diagnostics without changing configured field semantics.
