<?php

namespace MIIM\ModelContracting\Services;

use InvalidArgumentException;

final class ModelFilterParser
{
    /**
     * Поддержка legacy ?filter[field]=x и ?filter[field][op]=x.
     *
     * @param  array<string, mixed>  $filterParams
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    public function parse(array $filterParams): array
    {
        $conditions = [];

        foreach ($filterParams as $field => $value) {
            if (! is_string($field) || $field === '' || str_starts_with($field, '_')) {
                continue;
            }

            if (is_array($value) && $this->isOperatorMap($value)) {
                foreach ($value as $operator => $operatorValue) {
                    if (! is_string($operator)) {
                        continue;
                    }

                    $conditions[] = [
                        'field' => $field,
                        'operator' => strtolower($operator),
                        'value' => $operatorValue,
                    ];
                }

                continue;
            }

            $conditions[] = [
                'field' => $field,
                'operator' => ModelFilterOperator::EQ,
                'value' => $value,
            ];
        }

        return $conditions;
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
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     */
    public function validate(array $conditions, array $filterableFields): void
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $operator = $condition['operator'];

            if (! isset($filterableFields[$field])) {
                throw new InvalidArgumentException("Field '{$field}' is not filterable.");
            }

            if (! in_array($operator, ModelFilterOperator::ALL, true)) {
                throw new InvalidArgumentException("Unknown filter operator '{$operator}'.");
            }

            $type = (string) ($filterableFields[$field]['type'] ?? 'string');
            if (! in_array($operator, ModelFilterOperator::allowedForType($type), true)) {
                throw new InvalidArgumentException(
                    "Operator '{$operator}' is not allowed for field '{$field}' of type '{$type}'."
                );
            }
        }
    }
}
