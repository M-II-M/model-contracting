<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Фильтрация по атрибутам поля type=extensions: ?filter[options.name][eq]=value
 */
final class ExtensionFieldFilter
{
    public function apply(
        Builder $query,
        string $column,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        $driver = $query->getConnection()->getDriverName();
        $wrappedColumn = $query->getGrammar()->wrap($column);

        match ($driver) {
            'pgsql' => $this->applyPostgres($query, $wrappedColumn, $attributeKey, $operator, $value),
            'mysql' => $this->applyMysql($query, $wrappedColumn, $attributeKey, $operator, $value),
            'sqlite' => $this->applySqlite($query, $wrappedColumn, $attributeKey, $operator, $value),
            default => throw new InvalidArgumentException("Extension filters are not supported for driver '{$driver}'."),
        };
    }

    private function applyPostgres(
        Builder $query,
        string $wrappedColumn,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        $jsonColumn = "CAST({$wrappedColumn} AS jsonb)";

        if ($operator === ModelFilterOperator::IS_NULL) {
            $exists = $this->postgresAttributeExistsSql($jsonColumn);
            if ($this->castBoolean($value)) {
                $query->whereRaw("NOT ({$exists})", [$attributeKey]);

                return;
            }

            $query->whereRaw($exists, [$attributeKey]);

            return;
        }

        [$sql, $bindings] = $this->buildPostgresValuePredicate($operator, $value);
        $query->whereRaw(
            "EXISTS (
                SELECT 1
                FROM jsonb_array_elements({$jsonColumn}) AS ext
                WHERE ext->>'name' = ?
                  AND ({$sql})
            )",
            array_merge([$attributeKey], $bindings),
        );
    }

    private function buildPostgresValuePredicate(string $operator, mixed $value): array
    {
        return match ($operator) {
            ModelFilterOperator::EQ => ["LOWER(ext->>'value') = ?", [mb_strtolower($this->stringValue($value))]],
            ModelFilterOperator::NEQ => ["LOWER(ext->>'value') != ?", [mb_strtolower($this->stringValue($value))]],
            ModelFilterOperator::CONTAINS => ["ext->>'value' ILIKE ?", ['%'.$this->escapeLike($this->stringValue($value)).'%']],
            ModelFilterOperator::STARTS_WITH => ["ext->>'value' ILIKE ?", [$this->escapeLike($this->stringValue($value)).'%']],
            ModelFilterOperator::ENDS_WITH => ["ext->>'value' ILIKE ?", ['%'.$this->escapeLike($this->stringValue($value))]],
            ModelFilterOperator::IN => $this->buildPostgresInPredicate($value, false),
            ModelFilterOperator::NOT_IN => $this->buildPostgresInPredicate($value, true),
            default => throw new InvalidArgumentException("Operator '{$operator}' is not supported for extensions attributes."),
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildPostgresInPredicate(mixed $value, bool $negate): array
    {
        $items = $this->parseList($value);
        if ($items === []) {
            throw new InvalidArgumentException("Operator 'in' requires at least one value.");
        }

        $placeholders = implode(', ', array_fill(0, count($items), '?'));
        $lowered = array_map(static fn (string $v) => mb_strtolower($v), $items);
        $operator = $negate ? 'NOT IN' : 'IN';

        return ["LOWER(ext->>'value') {$operator} ({$placeholders})", $lowered];
    }

    private function postgresAttributeExistsSql(string $jsonColumn): string
    {
        return "EXISTS (
            SELECT 1
            FROM jsonb_array_elements({$jsonColumn}) AS ext
            WHERE ext->>'name' = ?
              AND ext->>'value' IS NOT NULL
              AND ext->>'value' != ''
        )";
    }

    private function applyMysql(
        Builder $query,
        string $wrappedColumn,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        if ($operator === ModelFilterOperator::IS_NULL) {
            $existsSql = $this->mysqlAttributeExistsSql($wrappedColumn);
            if ($this->castBoolean($value)) {
                $query->whereRaw("NOT ({$existsSql})", [$attributeKey]);

                return;
            }

            $query->whereRaw($existsSql, [$attributeKey]);

            return;
        }

        [$valueSql, $bindings] = $this->buildMysqlValuePredicate($operator, $value);
        $query->whereRaw(
            "EXISTS (
                SELECT 1
                FROM JSON_TABLE(
                    {$wrappedColumn},
                    '$[*]' COLUMNS (
                        name VARCHAR(255) PATH '$.name',
                        value_text VARCHAR(4000) PATH '$.value'
                    )
                ) AS ext
                WHERE ext.name = ?
                  AND ({$valueSql})
            )",
            array_merge([$attributeKey], $bindings),
        );
    }

    private function buildMysqlValuePredicate(string $operator, mixed $value): array
    {
        return match ($operator) {
            ModelFilterOperator::EQ => ['LOWER(ext.value_text) = ?', [mb_strtolower($this->stringValue($value))]],
            ModelFilterOperator::NEQ => ['LOWER(ext.value_text) != ?', [mb_strtolower($this->stringValue($value))]],
            ModelFilterOperator::CONTAINS => ['LOWER(ext.value_text) LIKE ?', ['%'.mb_strtolower($this->escapeLike($this->stringValue($value))).'%']],
            ModelFilterOperator::STARTS_WITH => ['LOWER(ext.value_text) LIKE ?', [mb_strtolower($this->escapeLike($this->stringValue($value))).'%']],
            ModelFilterOperator::ENDS_WITH => ['LOWER(ext.value_text) LIKE ?', ['%'.mb_strtolower($this->escapeLike($this->stringValue($value))).'%']],
            ModelFilterOperator::IN => $this->buildMysqlInPredicate($value, false),
            ModelFilterOperator::NOT_IN => $this->buildMysqlInPredicate($value, true),
            default => throw new InvalidArgumentException("Operator '{$operator}' is not supported for extensions attributes."),
        };
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildMysqlInPredicate(mixed $value, bool $negate): array
    {
        $items = $this->parseList($value);
        if ($items === []) {
            throw new InvalidArgumentException("Operator 'in' requires at least one value.");
        }

        $placeholders = implode(', ', array_fill(0, count($items), '?'));
        $lowered = array_map(static fn (string $v) => mb_strtolower($v), $items);
        $operator = $negate ? 'NOT IN' : 'IN';

        return ["LOWER(ext.value_text) {$operator} ({$placeholders})", $lowered];
    }

    private function mysqlAttributeExistsSql(string $wrappedColumn): string
    {
        return "EXISTS (
            SELECT 1
            FROM JSON_TABLE(
                {$wrappedColumn},
                '$[*]' COLUMNS (
                    name VARCHAR(255) PATH '$.name',
                    value_text VARCHAR(4000) PATH '$.value'
                )
            ) AS ext
            WHERE ext.name = ?
              AND ext.value_text IS NOT NULL
              AND ext.value_text != ''
        )";
    }

    private function applySqlite(
        Builder $query,
        string $wrappedColumn,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        if ($operator === ModelFilterOperator::IS_NULL) {
            $existsSql = "EXISTS (
                SELECT 1
                FROM json_each({$wrappedColumn}) AS ext
                WHERE json_extract(ext.value, '$.name') = ?
                  AND json_extract(ext.value, '$.value') IS NOT NULL
                  AND json_extract(ext.value, '$.value') != ''
            )";

            if ($this->castBoolean($value)) {
                $query->whereRaw("NOT ({$existsSql})", [$attributeKey]);

                return;
            }

            $query->whereRaw($existsSql, [$attributeKey]);

            return;
        }

        $stringValue = mb_strtolower($this->stringValue($value));
        $pattern = match ($operator) {
            ModelFilterOperator::EQ => $stringValue,
            ModelFilterOperator::NEQ => $stringValue,
            ModelFilterOperator::CONTAINS => '%'.mb_strtolower($this->escapeLike($this->stringValue($value))).'%',
            ModelFilterOperator::STARTS_WITH => mb_strtolower($this->escapeLike($this->stringValue($value))).'%',
            ModelFilterOperator::ENDS_WITH => '%'.mb_strtolower($this->escapeLike($this->stringValue($value))).'%',
            default => throw new InvalidArgumentException("Operator '{$operator}' is not supported for extensions attributes."),
        };

        $compare = match ($operator) {
            ModelFilterOperator::EQ => '=',
            ModelFilterOperator::NEQ => '!=',
            ModelFilterOperator::CONTAINS, ModelFilterOperator::STARTS_WITH, ModelFilterOperator::ENDS_WITH => 'LIKE',
            default => throw new InvalidArgumentException("Operator '{$operator}' is not supported for extensions attributes."),
        };

        $query->whereRaw(
            "EXISTS (
                SELECT 1
                FROM json_each({$wrappedColumn}) AS ext
                WHERE json_extract(ext.value, '$.name') = ?
                  AND LOWER(json_extract(ext.value, '$.value')) {$compare} ?
            )",
            [$attributeKey, $pattern],
        );
    }

    /**
     * @return list<string>
     */
    private function parseList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn ($v) => trim((string) $v), $value));
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Filter value must be scalar or comma-separated list.');
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string) $value)),
            static fn (string $part) => $part !== '',
        ));
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Filter value must be scalar.');
        }

        return (string) $value;
    }

    private function castBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function escapeLike(string $pattern): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $pattern,
        );
    }
}
