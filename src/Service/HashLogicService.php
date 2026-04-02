<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Topdata\TopdataFoundationSW6\Service\TopConfigRegistry;

/**
 * Service for calculating address hashes based on configurable fields.
 * This service normalizes address data and generates SHA-256 hashes for deduplication purposes.
 * It supports various address fields and allows configuration of which fields to include in the hash calculation.
 */
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
     * Returns the list of enabled fields for hash calculation.
     * If configuration is not available, returns default fields.
     * Filters out any fields that are not defined in FIELD_MAP.
     *
     * @return string[] List of enabled field names
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

    /**
     * Generates SQL expression for calculating address hash in database.
     * Uses SHA-256 algorithm with concatenated normalized field values.
     *
     * @param string $alias SQL table alias to use in the expression
     * @return string SQL expression for hash calculation
     */
    public function getSqlExpression(string $alias = 'NEW'): string
    {
        // ---- Build SQL parts for each enabled field
        $parts = [];
        foreach ($this->getEnabledFields() as $field) {
            $parts[] = sprintf(self::FIELD_MAP[$field]['sql'], $alias);
        }

        // ---- Combine parts into final SQL expression
        return 'SHA2(LOWER(CONCAT(' . implode(', ', $parts) . ')), 256)';
    }

    /**
     * Calculates a hash for the given address data.
     * Uses only enabled fields and normalizes the values before hashing.
     *
     * @param array<string, mixed> $data Address data array
     * @return string SHA-256 hash of the address data
     */
    public function calculateHash(array $data): string
    {
        return $this->calculateHashDetailed($data)['hash'];
    }

    /**
     * Calculates a hash for the given address data with detailed information about the process.
     * Returns the hash along with information about used, ignored, and missing fields.
     *
     * @param array<string, mixed> $data Address data array
     * @return array{hash: string, used: array<string, array{original: mixed, normalized: string}>, ignored: array<string, mixed>, missing: string[]}
     *         Hash calculation result with detailed information
     */
    public function calculateHashDetailed(array $data): array
    {
        // ---- Initialize variables and get enabled fields
        $enabledFields = $this->getEnabledFields();
        $used = [];
        $ignored = [];
        $missing = [];
        $concat = '';

        // ---- Process input data and identify ignored fields
        foreach ($data as $key => $value) {
            $camelKey = $this->toCamelCase((string)$key);
            if (!in_array($camelKey, $enabledFields, true) && !in_array((string)$key, $enabledFields, true)) {
                $ignored[(string)$key] = $value;
            }
        }

        // ---- Process enabled fields and prepare for hashing
        foreach ($enabledFields as $field) {
            $value = $this->resolveInputValue($data, $field);
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

        // ---- Calculate final hash and return results
        return [
            'hash'    => hash('sha256', $concat),
            'used'    => $used,
            'ignored' => $ignored,
            'missing' => $missing,
        ];
    }

    /**
     * Checks if a field was provided in the input data.
     * Checks both camelCase and snake_case versions of the field name.
     *
     * @param array<string, mixed> $data Input data array
     * @param string $field Field name to check
     * @return bool True if the field was provided in the data
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
     * Resolves the value for a field from the input data.
     * Checks both camelCase and snake_case versions of the field name.
     *
     * @param array<string, mixed> $data Input data array
     * @param string $field Field name to resolve
     * @return mixed The field value or empty string if not found
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

    /**
     * Converts a snake_case string to camelCase.
     *
     * @param string $string Input string in snake_case
     * @return string String converted to camelCase
     */
    private function toCamelCase(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}