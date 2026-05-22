<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Фильтрация по атрибутам поля type=extensions.
 */
final class ExtensionFieldFilter
{
    private const MATCH_BY_VALUE_ONLY_KEY = 'value';
    public function apply(
        Builder $query,
        string $column,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        $driver = $query->getConnection()->getDriverName();
        // Квалифицированный столбец (table.column) — обязателен для коррелированного JSON_TABLE в MySQL.
        $jsonColumn = $query->qualifyColumn($column);

        match ($driver) {
            'pgsql' => $this->applyPostgres($query, $jsonColumn, $attributeKey, $operator, $value),
            'mysql' => $this->applyMysql($query, $jsonColumn, $attributeKey, $operator, $value),
            'sqlite' => $this->applySqlite($query, $jsonColumn, $attributeKey, $operator, $value),
            default => throw new InvalidArgumentException("Extension filters are not supported for driver '{$driver}'."),
        };
    }

    private function applyPostgres(
        Builder $query,
        string $qualifiedColumn,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        $jsonColumn = "CAST({$qualifiedColumn} AS jsonb)";

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

        if ($this->matchesByValueOnly($attributeKey)) {
            $query->whereRaw(
                "EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements({$jsonColumn}) AS ext
                    WHERE ({$sql})
                )",
                $bindings,
            );

            return;
        }

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

    private function matchesByValueOnly(string $attributeKey): bool
    {
        return $attributeKey === self::MATCH_BY_VALUE_ONLY_KEY;
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
        string $qualifiedColumn,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        if ($operator === ModelFilterOperator::IS_NULL) {
            $this->applyMysqlIsNull($query, $qualifiedColumn, $attributeKey, $value);

            return;
        }

        [$valueSql, $bindings] = $this->buildMysqlValuePredicate($operator, $value);
        $this->applyMysqlJsonTableMatch(
            $query,
            $qualifiedColumn,
            $attributeKey,
            $valueSql,
            $bindings,
            false,
        );
    }

    /**
     * MySQL: JSON_TABLE в EXISTS не коррелируется с внешней строкой — используем IN + INNER JOIN.
     *
     * @param  list<mixed>  $bindings
     */
    private function applyMysqlJsonTableMatch(
        Builder $query,
        string $qualifiedOptionsColumn,
        string $attributeKey,
        string $valueSql,
        array $bindings,
        bool $negate = false,
    ): void {
        $model = $query->getModel();
        $grammar = $query->getGrammar();
        $table = $grammar->wrapTable($model->getTable());
        $idColumn = $grammar->wrap($model->getKeyName());
        $qualifiedId = $query->qualifyColumn($model->getKeyName());

        $jsonColumns = $this->matchesByValueOnly($attributeKey)
            ? "value_text VARCHAR(4000) PATH '\$.value'"
            : "name VARCHAR(255) PATH '\$.name', value_text VARCHAR(4000) PATH '\$.value'";

        $namePredicate = $this->matchesByValueOnly($attributeKey)
            ? '1 = 1'
            : 'ext.name = ?';

        $nameBindings = $this->matchesByValueOnly($attributeKey) ? [] : [$attributeKey];

        $subquery = "SELECT {$table}.{$idColumn}
            FROM {$table}
            INNER JOIN JSON_TABLE(
                {$qualifiedOptionsColumn},
                '\$[*]' COLUMNS ({$jsonColumns})
            ) AS ext ON {$namePredicate} AND ({$valueSql})";

        $inOperator = $negate ? 'NOT IN' : 'IN';
        $query->whereRaw("{$qualifiedId} {$inOperator} ({$subquery})", array_merge($nameBindings, $bindings));
    }

    private function applyMysqlIsNull(
        Builder $query,
        string $qualifiedOptionsColumn,
        string $attributeKey,
        mixed $value,
    ): void {
        $this->applyMysqlJsonTableMatch(
            $query,
            $qualifiedOptionsColumn,
            $attributeKey,
            'ext.value_text IS NOT NULL AND ext.value_text != ?',
            [''],
            $this->castBoolean($value),
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

    private function applySqlite(
        Builder $query,
        string $qualifiedColumn,
        string $attributeKey,
        string $operator,
        mixed $value,
    ): void {
        if ($operator === ModelFilterOperator::IS_NULL) {
            $existsSql = "EXISTS (
                SELECT 1
                FROM json_each({$qualifiedColumn}) AS ext
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

        $scalarValue = $this->stringValue($value);
        $pattern = match ($operator) {
            ModelFilterOperator::EQ, ModelFilterOperator::NEQ => $scalarValue,
            ModelFilterOperator::CONTAINS => '%'.$this->escapeLike($scalarValue).'%',
            ModelFilterOperator::STARTS_WITH => $this->escapeLike($scalarValue).'%',
            ModelFilterOperator::ENDS_WITH => '%'.$this->escapeLike($scalarValue).'%',
            default => throw new InvalidArgumentException("Operator '{$operator}' is not supported for extensions attributes."),
        };

        $compare = match ($operator) {
            ModelFilterOperator::EQ => '=',
            ModelFilterOperator::NEQ => '!=',
            ModelFilterOperator::CONTAINS, ModelFilterOperator::STARTS_WITH, ModelFilterOperator::ENDS_WITH => 'LIKE',
            default => throw new InvalidArgumentException("Operator '{$operator}' is not supported for extensions attributes."),
        };

        if ($this->matchesByValueOnly($attributeKey)) {
            $query->whereRaw(
                "EXISTS (
                    SELECT 1
                    FROM json_each({$qualifiedColumn}) AS ext
                    WHERE json_extract(ext.value, '$.value') {$compare} ?
                )",
                [$pattern],
            );

            return;
        }

        $query->whereRaw(
            "EXISTS (
                SELECT 1
                FROM json_each({$qualifiedColumn}) AS ext
                WHERE json_extract(ext.value, '$.name') = ?
                  AND json_extract(ext.value, '$.value') {$compare} ?
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
