<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Service;

use Doctrine\DBAL\Connection;
use Topdata\TopdataFoundationSW6\Service\TopConfigRegistry;

/**
 * Service for calculating address hashes based on configurable fields.
 * This service normalizes address data and generates SHA-256 hashes for deduplication purposes.
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
        'salutationId'           => ['sql' => "IFNULL((SELECT salutation_key FROM salutation WHERE id = %s.salutation_id), '')", 'api' => 'salutationId'],
        'firstName'              => ['sql' => "REGEXP_REPLACE(IFNULL(%s.first_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'firstName'],
        'lastName'               => ['sql' => "REGEXP_REPLACE(IFNULL(%s.last_name, ''), '[^a-zA-Z0-9]', '')", 'api' => 'lastName'],
        'title'                  => ['sql' => "REGEXP_REPLACE(IFNULL(%s.title, ''), '[^a-zA-Z0-9]', '')", 'api' => 'title'],
        'countryId'              => ['sql' => "IFNULL((SELECT iso FROM country WHERE id = %s.country_id), '')", 'api' => 'countryId'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ?TopConfigRegistry $topConfigRegistry = null
    ) {
    }

    public function getEnabledFields(): array
    {
        try {
            if ($this->topConfigRegistry === null) {
                $fields = self::DEFAULT_FIELDS;
            } else {
                $fields = $this->topConfigRegistry->getTopConfig('TopdataAddressHashesSW6')->get('hashFields');
                if (!\is_array($fields) || $fields === []) {
                    $fields = self::DEFAULT_FIELDS;
                }
            }

            $filtered = array_values(array_filter($fields, static fn($field): bool => \is_string($field) && isset(self::FIELD_MAP[$field])));
            sort($filtered);

            return $filtered;
        } catch (\Throwable) {
            $default = self::DEFAULT_FIELDS;
            sort($default);
            return $default;
        }
    }

    public function getHashFieldsJson(): string
    {
        return json_encode($this->getEnabledFields(), JSON_THROW_ON_ERROR);
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
     * @return array<string, array{processed: int, inserted: int, updated: int}>
     */
    public function refreshAllHashes(): array
    {
        $hashFieldsJson = $this->getHashFieldsJson();
        $hashFieldsSqlLiteral = "'" . str_replace("'", "''", $hashFieldsJson) . "'";

        return [
            'customer_address' => $this->refreshTable('customer_address', 'tdah_customer_address_extension', null, $hashFieldsSqlLiteral),
            'order_address'    => $this->refreshTable('order_address', 'tdah_order_address_extension', 'version_id', $hashFieldsSqlLiteral),
        ];
    }

    /**
     * @return array{processed: int, inserted: int, updated: int}
     */
    private function refreshTable(string $table, string $extensionTable, ?string $versionField, string $hashFieldsSqlLiteral): array
    {
        $hashExpr = $this->getSqlExpression($table);

        $insertColumns = $versionField !== null
            ? '(address_id, address_version_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)'
            : '(address_id, fingerprint, hash_fields, hash_fields_changed_at, hash_changed_at, created_at, updated_at)';

        $selectColumns = $versionField !== null
            ? "id, {$versionField}, {$hashExpr}, {$hashFieldsSqlLiteral}, NOW(3), NOW(3), NOW(3), NULL"
            : "id, {$hashExpr}, {$hashFieldsSqlLiteral}, NOW(3), NOW(3), NOW(3), NULL";

        $existingCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$extensionTable}`");
        $sourceCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$table}`");

        $this->connection->executeStatement(
            "REPLACE INTO `{$extensionTable}` {$insertColumns}
            SELECT {$selectColumns} FROM `{$table}`"
        );

        $newCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$extensionTable}`");

        $inserted = max(0, $newCount - $existingCount);
        $updated = $sourceCount - $inserted;

        return [
            'processed' => $sourceCount,
            'inserted'  => $inserted,
            'updated'   => $updated,
        ];
    }

    public function calculateHash(array $data): string
    {
        return $this->calculateHashDetailed($data)['hash'];
    }

    public function calculateHashDetailed(array $data): array
    {
        $enabledFields = $this->getEnabledFields();
        $used = [];
        $ignored = [];
        $missing = [];
        $concat = '';

        foreach ($data as $key => $value) {
            $camelKey = $this->toCamelCase((string)$key);
            if (!in_array($camelKey, $enabledFields, true) && !in_array((string)$key, $enabledFields, true)) {
                $ignored[(string)$key] = $value;
            }
        }

        foreach ($enabledFields as $field) {
            $value = $this->resolveInputValue($data, $field);
            $wasProvided = $this->wasFieldProvided($data, $field);

            if (!$wasProvided) {
                $missing[] = $field;
            }

            $originalValue = $value;

            if (\in_array($field, ['salutationId', 'countryId'], true)) {
                $rawHex = str_replace('-', '', (string)$value);
                if (strlen($rawHex) === 32 && ctype_xdigit($rawHex)) {
                    if ($field === 'countryId') {
                        $dbVal = $this->connection->fetchOne(
                            'SELECT iso FROM country WHERE id = UNHEX(:id)',
                            ['id' => $rawHex]
                        );
                    } else {
                        $dbVal = $this->connection->fetchOne(
                            'SELECT salutation_key FROM salutation WHERE id = UNHEX(:id)',
                            ['id' => $rawHex]
                        );
                    }
                    $value = $dbVal ?: '';
                } else {
                    $value = (string)$originalValue;
                }
            } else {
                $value = preg_replace('/[^a-zA-Z0-9]/', '', (string)$value) ?? '';
            }

            $normalizedValue = strtolower((string)$value);
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