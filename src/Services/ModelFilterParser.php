<?php

namespace MIIM\ModelContracting\Services;

use InvalidArgumentException;

final class ModelFilterParser
{
    /**
     * Поддержка:
     * - ?filter[field]=x
     * - ?filter[field][op]=x
     * - ?filter[options.name][eq]=x
     * - ?filter[options][name][eq]=x
     *
     * @param  array<string, mixed>  $filterParams
     * @return list<array{field: string, extension_key: string|null, operator: string, value: mixed}>
     */
    public function parse(array $filterParams): array
    {
        $conditions = [];

        foreach ($this->normalizeFilterParams($filterParams) as $field => $value) {
            if (! is_string($field) || $field === '' || str_starts_with($field, '_')) {
                continue;
            }

            ['field' => $baseField, 'extension_key' => $extensionKey] = $this->splitFieldKey($field);

            if (is_array($value) && $this->isOperatorMap($value)) {
                foreach ($value as $operator => $operatorValue) {
                    if (! is_string($operator)) {
                        continue;
                    }

                    $conditions[] = [
                        'field' => $baseField,
                        'extension_key' => $extensionKey,
                        'operator' => strtolower($operator),
                        'value' => $operatorValue,
                    ];
                }

                continue;
            }

            $conditions[] = [
                'field' => $baseField,
                'extension_key' => $extensionKey,
                'operator' => ModelFilterOperator::EQ,
                'value' => $value,
            ];
        }

        return $conditions;
    }

    /**
     * @param  array<string, mixed>  $filterParams
     * @return array<string, mixed>
     */
    private function normalizeFilterParams(array $filterParams): array
    {
        $normalized = [];

        foreach ($filterParams as $field => $value) {
            if (! is_string($field) || $field === '' || str_starts_with($field, '_')) {
                continue;
            }

            if (is_array($value) && ! $this->isOperatorMap($value)) {
                foreach ($value as $attribute => $attributeValue) {
                    if (! is_string($attribute) || $attribute === '') {
                        continue;
                    }

                    $normalized[$field.'.'.$attribute] = $attributeValue;
                }

                continue;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{field: string, extension_key: string|null}
     */
    private function splitFieldKey(string $field): array
    {
        if (! str_contains($field, '.')) {
            return ['field' => $field, 'extension_key' => null];
        }

        [$baseField, $extensionKey] = explode('.', $field, 2);
        $extensionKey = trim($extensionKey);

        if ($baseField === '' || $extensionKey === '') {
            throw new InvalidArgumentException(
                "Invalid extensions filter key '{$field}'. Use filter[{$field}.attribute][operator]=value."
            );
        }

        return ['field' => $baseField, 'extension_key' => $extensionKey];
    }

    /**
     * @param  array<string|int, mixed>  $value
     */
    private function isOperatorMap(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (is_string($key) && in_array(strtolower($key), ModelFilterOperator::ALL, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $filterableFields
     * @param  list<array{field: string, extension_key: string|null, operator: string, value: mixed}>  $conditions
     */
    public function validate(array $conditions, array $filterableFields): void
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $extensionKey = $condition['extension_key'] ?? null;
            $operator = $condition['operator'];

            if (! isset($filterableFields[$field])) {
                throw new InvalidArgumentException("Field '{$field}' is not filterable.");
            }

            if (! in_array($operator, ModelFilterOperator::ALL, true)) {
                throw new InvalidArgumentException("Unknown filter operator '{$operator}'.");
            }

            $type = (string) ($filterableFields[$field]['type'] ?? 'string');

            if ($type === 'extensions') {
                if ($extensionKey === null) {
                    throw new InvalidArgumentException(
                        "Field '{$field}' has type extensions. Use filter[{$field}][attribute][operator]=value, for example filter[{$field}][name][eq]=РОССИЯ or filter[{$field}][value][eq]=РОССИЯ to match any option value."
                    );
                }

                if (! in_array($operator, ModelFilterOperator::allowedForType('string'), true)) {
                    throw new InvalidArgumentException(
                        "Operator '{$operator}' is not allowed for extensions attribute '{$field}.{$extensionKey}'."
                    );
                }

                continue;
            }

            if ($extensionKey !== null) {
                throw new InvalidArgumentException(
                    "Field '{$field}' does not support attribute filter '{$field}.{$extensionKey}'."
                );
            }

            if (! in_array($operator, ModelFilterOperator::allowedForType($type), true)) {
                throw new InvalidArgumentException(
                    "Operator '{$operator}' is not allowed for field '{$field}' of type '{$type}'."
                );
            }
        }
    }
}
