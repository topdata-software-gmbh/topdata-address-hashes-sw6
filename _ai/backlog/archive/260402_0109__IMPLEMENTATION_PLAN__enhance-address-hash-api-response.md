---
filename: "_ai/backlog/archive/260402_0109__IMPLEMENTATION_PLAN__enhance-address-hash-api-response.md"
title: "Enhance Address Hash API Response with Field Usage Details"
createdAt: 2026-04-02 01:09
createdBy: Cascade [Claude 3.5 Sonnet]
updatedAt: 2026-04-02 01:09
updatedBy: Cascade [Claude 3.5 Sonnet]
status: completed
priority: medium
tags: [api, hash-calculation, enhancement, visibility]
project: topdata-address-hashes-sw6
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## Problem Statement

The current `/api/_action/topdata/calculate-address-hash` endpoint only returns the fingerprint and the raw input received. Users cannot determine:
- Which input fields were actually used for hash calculation
- Which input fields were provided but ignored (not configured for hashing)
- Which fields were missing from input but used for hash calculation (empty values)

This lack of visibility makes debugging hash mismatches difficult.

## Implementation Notes

**Project Structure:**
- `src/Controller/AddressHashApiController.php` - API endpoint controller
- `src/Service/HashLogicService.php` - Hash calculation logic

**Key Components:**
- `HashLogicService::calculateHash()` - Currently returns only the hash string
- `HashLogicService::getEnabledFields()` - Returns list of fields configured for hashing
- `HashLogicService::resolveInputValue()` - Resolves field value from input data (handles camelCase/snake_case)

**Default Fields Used for Hashing:** `['street', 'zipcode', 'city', 'lastName', 'countryId']`

**All Possible Fields:** Defined in `FIELD_MAP` constant including phoneNumber, additionalAddressLine1/2, company, department, salutationId, firstName, title

## Phase 1: Enhance HashLogicService

**Objective:** Modify `calculateHash()` to return detailed calculation metadata alongside the hash.

**Tasks:**
1. Create a new data transfer method `calculateHashDetailed()` that returns:
   - The hash fingerprint
   - Fields used for calculation (field name, original value, normalized value)
   - Fields ignored (provided in input but not enabled)
   - Fields missing (enabled but not provided, treated as empty)

**Deliverables:**

[MODIFY] `src/Service/HashLogicService.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Topdata\TopdataFoundationSW6\Service\TopConfigRegistry;

class HashLogicService
{
    private const DEFAULT_FIELDS = ['street', 'zipcode', 'city', 'lastName', 'countryId'];

    private const FIELD_MAP = [
        'street'                 => ['sql' => "REGEXP_REPLACE(IFNULL(%s.street, ''), '[^a-zA-Z0-9]', '')", 'api' => 'street'],
        'zipcode'                => ['sql' => "REGEXP_REPLACE(IFNULL(%s.zipcode, ''), '[^a-zA-Z0-9]', '')", 'api' => 'zipcode'],
        'city'                   => ['sql' => "REGEXP_REPLACE(IFNULL(%s.city, ''), '[^a-zA-Z0-9]', '')", 'api' => 'city'],
        'phoneNumber'            => ['sql' => "REGEXP_REPLACE(IFNULL(%s.phone_number, ''), '[^a-zA-Z0-9]', '')", 'api' => 'phoneNumber'],
        'additionalAddressLine1' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.additional_address_line1, ''), '[^a-zA-Z0-9]', '')", 'api' => 'additionalAddressLine1'],
        'additionalAddressLine2' => ['sql' => "REGEXP_REPLACE(IFNULL(%s.additional_address_line2, ''), '[^a-zA-Z0-9]', '')", 'api' => 'additionalAddressLine2'],
        'company'                => ['sql' => "REGEXP_REPLACE(IFNULL(%s.company, ''), '[^a-zA-Z0-9]', '')", 'api' => 'company'],
        'department'             => ['sql' => "REGEXP_REPLACE(IFNULL(%s.department, ''), '[^a-zA-Z0-9]', '')", 'api' => 'department'],
        'salutationId'           => ['sql' => "IFNULL(HEX(%s.salutation_id), '')", 'api' => 'salutationId'],
        'firstName'              => ['sql' => "REGEXP_REPLACE(IFNULL(%s.first_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'firstName'],
        'lastName'               => ['sql' => "REGEXP_REPLACE(IFNULL(%s.last_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'lastName'],
        'title'                  => ['sql' => "REGEXP_REPLACE(IFNULL(%s.title, ''), '[^a-zA-Z0-9]', '')", 'api' => 'title'],
        'countryId'              => ['sql' => "IFNULL(HEX(%s.country_id), '')", 'api' => 'countryId'],
    ];

    public function __construct(private readonly ?TopConfigRegistry $topConfigRegistry = null)
    {
    }

    /**
     * @return string[]
     */
    public function getEnabledFields(): array
    {
        try {
            if ($this->topConfigRegistry === null) {
                return self::DEFAULT_FIELDS;
            }

            $fields = $this->topConfigRegistry->getTopConfig('TopdataAddressHashesSW6')->get('hashFields');
            if (!\is_array($fields) || $fields === []) {
                return self::DEFAULT_FIELDS;
            }

            return array_values(array_filter($fields, static fn($field): bool => \is_string($field) && isset(self::FIELD_MAP[$field])));
        } catch (\Throwable) {
            return self::DEFAULT_FIELDS;
        }
    }

    public function getSqlExpression(string $alias = 'NEW'): string
    {
        $parts = [];
        foreach ($this->getEnabledFields() as $field) {
            $parts[] = sprintf(self::FIELD_MAP[$field]['sql'], $alias);
        }

        return 'SHA2(LOWER(CONCAT(' . implode(', ', $parts) . ')), 256)';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function calculateHash(array $data): string
    {
        return $this->calculateHashDetailed($data)['hash'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{hash: string, used: array<string, array{original: mixed, normalized: string}>, ignored: array<string, mixed>, missing: string[]}
     */
    public function calculateHashDetailed(array $data): array
    {
        $enabledFields = $this->getEnabledFields();
        $used = [];
        $ignored = [];
        $missing = [];
        $concat = '';

        // Identify which input fields are enabled and which are ignored
        foreach ($data as $key => $value) {
            $camelKey = $this->toCamelCase($key);
            if (!in_array($camelKey, $enabledFields, true) && !in_array($key, $enabledFields, true)) {
                $ignored[$key] = $value;
            }
        }

        foreach ($enabledFields as $field) {
            $value = $this->resolveInputValue($data, $field);

            // Check if field was actually provided in input
            $wasProvided = $this->wasFieldProvided($data, $field);

            if (!$wasProvided) {
                $missing[] = $field;
            }

            $originalValue = $value;

            if (\in_array($field, ['salutationId', 'countryId'], true)) {
                $value = str_replace('-', '', (string)$value);
            } else {
                $value = preg_replace('/[^a-zA-Z0-9]/', '', (string)$value) ?? '';
            }

            $normalizedValue = strtolower($value);
            $concat .= $normalizedValue;

            $used[$field] = [
                'original'   => $originalValue,
                'normalized' => $normalizedValue,
            ];
        }

        return [
            'hash'    => hash('sha256', $concat),
            'used'    => $used,
            'ignored' => $ignored,
            'missing' => $missing,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function wasFieldProvided(array $data, string $field): bool
    {
        if (array_key_exists($field, $data)) {
            return true;
        }

        $snakeCaseField = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        if (array_key_exists($snakeCaseField, $data)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveInputValue(array $data, string $field): mixed
    {
        if (array_key_exists($field, $data)) {
            return $data[$field];
        }

        $snakeCaseField = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        if (array_key_exists($snakeCaseField, $data)) {
            return $data[$snakeCaseField];
        }

        return '';
    }

    private function toCamelCase(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}
```

## Phase 2: Update API Controller

**Objective:** Modify the controller to use the new detailed method and return enhanced response.

**Tasks:**
1. Update `calculate()` method to call `calculateHashDetailed()` instead of `calculateHash()`
2. Restructure the response payload to include:
   - `fingerprint` - the calculated hash
   - `fields_used` - detailed info about fields used for hashing
   - `fields_ignored` - fields provided but not used
   - `fields_missing` - fields enabled but not provided
   - `config` - current hash configuration (enabled fields)

**Deliverables:**

[MODIFY] `src/Controller/AddressHashApiController.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Controller\AbstractTopdataApiController;

#[Route(defaults: ['_routeScope' => ['api']])]
class AddressHashApiController extends AbstractTopdataApiController
{
    public function __construct(private readonly HashLogicService $hashLogicService)
    {
    }

    #[Route(
        path: '/api/_action/topdata/address-hash-config',
        name: 'api.action.topdata.address-hash-config',
        methods: ['GET']
    )]
    public function getConfig(): JsonResponse
    {
        return $this->payloadResponse([
            'algorithm'     => 'SHA256',
            'normalization' => 'lowercase, non-alphanumeric removed',
            'fields'        => $this->hashLogicService->getEnabledFields(),
            'sql_template'  => $this->hashLogicService->getSqlExpression('TABLE_ALIAS'),
        ]);
    }

    #[Route(
        path: '/api/_action/topdata/calculate-address-hash',
        name: 'api.action.topdata.calculate-address-hash',
        methods: ['POST']
    )]
    public function calculate(Request $request): JsonResponse
    {
        $data = [];

        if ($request->request->count() > 0) {
            $data = $request->request->all();
        } else {
            $decoded = json_decode((string)$request->getContent(), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($data === []) {
            return $this->errorResponse('No address data provided', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->hashLogicService->calculateHashDetailed($data);

        return $this->payloadResponse([
            'fingerprint'    => $result['hash'],
            'fields_used'    => $result['used'],
            'fields_ignored' => $result['ignored'],
            'fields_missing' => $result['missing'],
            'config'         => [
                'enabled_fields' => $this->hashLogicService->getEnabledFields(),
            ],
        ]);
    }
}
```

## Phase 3: Verification

**Objective:** Test the enhanced API response.

**Verification Steps:**

1. **Test with standard input (all required fields):**
   ```bash
   curl -s -X POST \
     "$BASEURL/api/_action/topdata/calculate-address-hash" \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "street": "Musterstraße 1112",
       "zipcode": "08033",
       "city": "München",
       "lastName": "Mustermann",
       "countryId": "2f798c64371d4de996cbf0fe15475a18"
     }' | jq
   ```

   **Expected Response:**
   ```json
   {
     "success": true,
     "payload": {
       "fingerprint": "...",
       "fields_used": {
         "street": { "original": "Musterstraße 1112", "normalized": "musterstrasse1112" },
         "zipcode": { "original": "08033", "normalized": "08033" },
         "city": { "original": "München", "normalized": "mnchen" },
         "lastName": { "original": "Mustermann", "normalized": "mustermann" },
         "countryId": { "original": "2f798c64371d4de996cbf0fe15475a18", "normalized": "2f798c64371d4de996cbf0fe15475a18" }
       },
       "fields_ignored": {},
       "fields_missing": [],
       "config": { "enabled_fields": ["street", "zipcode", "city", "lastName", "countryId"] }
     }
   }
   ```

2. **Test with extra fields (should show ignored):**
   ```bash
   curl -s -X POST \
     "$BASEURL/api/_action/topdata/calculate-address-hash" \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "street": "Musterstraße 1",
       "zipcode": "08033",
       "city": "München",
       "lastName": "Mustermann",
       "countryId": "2f798c64371d4de996cbf0fe15475a18",
       "extraField": "will be ignored",
       "anotherUnused": 123
     }' | jq
   ```

   **Expected Response:**
   ```json
   {
     "success": true,
     "payload": {
       "fingerprint": "...",
       "fields_used": { ... },
       "fields_ignored": {
         "extraField": "will be ignored",
         "anotherUnused": 123
       },
       "fields_missing": [],
       "config": { ... }
     }
   }
   ```

3. **Test with missing fields (should show missing):**
   ```bash
   curl -s -X POST \
     "$BASEURL/api/_action/topdata/calculate-address-hash" \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "street": "Musterstraße 1",
       "city": "München"
     }' | jq
   ```

   **Expected Response:**
   ```json
   {
     "success": true,
     "payload": {
       "fingerprint": "...",
       "fields_used": {
         "street": { "original": "Musterstraße 1", "normalized": "musterstrasse1" },
         "zipcode": { "original": "", "normalized": "" },
         "city": { "original": "München", "normalized": "mnchen" },
         "lastName": { "original": "", "normalized": "" },
         "countryId": { "original": "", "normalized": "" }
       },
       "fields_ignored": {},
       "fields_missing": ["zipcode", "lastName", "countryId"],
       "config": { ... }
     }
   }
   ```

## Phase 4: Documentation Update

**Objective:** Update README.md to document the enhanced API response format.

**Deliverables:**

[MODIFY] `README.md` - Update the API documentation section to reflect the new response structure with examples.

## Phase 5: Report

**Objective:** Write implementation report.

**Deliverables:**

[NEW FILE] `_ai/backlog/reports/260402_0109__IMPLEMENTATION_REPORT__enhance-address-hash-api-response.md`

Content will include:
1. Summary of changes
2. Files modified
3. New response format documentation
4. Testing results
5. Example API calls and responses
