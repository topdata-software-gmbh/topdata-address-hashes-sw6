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
        $concat = '';

        foreach ($this->getEnabledFields() as $field) {
            $value = $this->resolveInputValue($data, $field);

            if (\in_array($field, ['salutationId', 'countryId'], true)) {
                $value = str_replace('-', '', (string)$value);
            } else {
                $value = preg_replace('/[^a-zA-Z0-9]/', '', (string)$value) ?? '';
            }

            $concat .= strtolower($value);
        }

        return hash('sha256', $concat);
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
}
