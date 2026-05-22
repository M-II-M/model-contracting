<?php

namespace MIIM\ModelContracting\Services;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ModelFilterQueryBuilder
{
    /**
     * @param  array<string, array<string, mixed>>  $filterableFields
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     */
    public function apply(Builder $query, array $conditions, array $filterableFields): void
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            if (! isset($filterableFields[$field])) {
                continue;
            }

            $type = (string) ($filterableFields[$field]['type'] ?? 'string');
            $this->applyCondition(
                $query,
                $field,
                $condition['operator'],
                $condition['value'],
                $type,
            );
        }
    }

    private function applyCondition(
        Builder $query,
        string $field,
        string $operator,
        mixed $value,
        string $type,
    ): void {
        match ($operator) {
            ModelFilterOperator::EQ => $this->applyEquals($query, $field, $value, $type),
            ModelFilterOperator::NEQ => $this->applyNotEquals($query, $field, $value, $type),
            ModelFilterOperator::GT => $query->where($field, '>', $this->castComparable($value, $type)),
            ModelFilterOperator::GTE => $query->where($field, '>=', $this->castComparable($value, $type)),
            ModelFilterOperator::LT => $query->where($field, '<', $this->castComparable($value, $type)),
            ModelFilterOperator::LTE => $query->where($field, '<=', $this->castComparable($value, $type)),
            ModelFilterOperator::CONTAINS => $this->applyLike($query, $field, '%'.$this->stringValue($value).'%'),
            ModelFilterOperator::STARTS_WITH => $this->applyLike($query, $field, $this->stringValue($value).'%'),
            ModelFilterOperator::ENDS_WITH => $this->applyLike($query, $field, '%'.$this->stringValue($value)),
            ModelFilterOperator::BETWEEN => $this->applyBetween($query, $field, $value, $type, false),
            ModelFilterOperator::NOT_BETWEEN => $this->applyBetween($query, $field, $value, $type, true),
            ModelFilterOperator::IN => $this->applyIn($query, $field, $value, $type, false),
            ModelFilterOperator::NOT_IN => $this->applyIn($query, $field, $value, $type, true),
            ModelFilterOperator::IS_NULL => $this->applyIsNull($query, $field, $value),
            default => throw new InvalidArgumentException("Unsupported operator '{$operator}'."),
        };
    }

    private function applyEquals(Builder $query, string $field, mixed $value, string $type): void
    {
        if ($type === 'boolean') {
            $query->where($field, $this->castBoolean($value));

            return;
        }

        $query->where($field, $this->castComparable($value, $type));
    }

    private function applyNotEquals(Builder $query, string $field, mixed $value, string $type): void
    {
        if ($type === 'boolean') {
            $query->where($field, '!=', $this->castBoolean($value));

            return;
        }

        $query->where($field, '!=', $this->castComparable($value, $type));
    }

    private function applyLike(Builder $query, string $field, string $pattern): void
    {
        $column = $query->getGrammar()->wrap($field);
        $escapedPattern = $this->escapeLikePattern($pattern);
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw("{$column} ILIKE ? ESCAPE '\\\\'", [$escapedPattern]);

            return;
        }

        $query->whereRaw('LOWER('.$column.') LIKE ? ESCAPE \'\\\\\'', [
            mb_strtolower($escapedPattern),
        ]);
    }

    private function escapeLikePattern(string $pattern): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $pattern,
        );
    }

    private function applyBetween(
        Builder $query,
        string $field,
        mixed $value,
        string $type,
        bool $negate,
    ): void {
        [$from, $to] = $this->parseRange($value);
        $fromCast = $this->castComparable($from, $type);
        $toCast = $this->castComparable($to, $type);

        if ($negate) {
            $query->where(function (Builder $q) use ($field, $fromCast, $toCast): void {
                $q->where($field, '<', $fromCast)->orWhere($field, '>', $toCast);
            });

            return;
        }

        $query->whereBetween($field, [$fromCast, $toCast]);
    }

    private function applyIn(Builder $query, string $field, mixed $value, string $type, bool $negate): void
    {
        $items = $this->parseList($value);
        $casted = array_map(fn (mixed $item) => $this->castComparable($item, $type), $items);

        if ($casted === []) {
            throw new InvalidArgumentException("Operator 'in' requires at least one value.");
        }

        if ($negate) {
            $query->whereNotIn($field, $casted);

            return;
        }

        $query->whereIn($field, $casted);
    }

    private function applyIsNull(Builder $query, string $field, mixed $value): void
    {
        if ($this->castBoolean($value)) {
            $query->whereNull($field);

            return;
        }

        $query->whereNotNull($field);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseRange(mixed $value): array
    {
        $parts = $this->parseList($value);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Range operators require exactly two comma-separated values.');
        }

        return [$parts[0], $parts[1]];
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
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('String filter value must be scalar.');
        }

        return (string) $value;
    }

    private function castComparable(mixed $value, string $type): mixed
    {
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Filter value must be scalar.');
        }

        return match ($type) {
            'integer' => (int) $value,
            'float' => (float) $value,
            'boolean' => $this->castBoolean($value),
            'date' => $this->castDate($value, false),
            'datetime' => $this->castDate($value, true),
            default => (string) $value,
        };
    }

    private function castDate(mixed $value, bool $withTime): string
    {
        try {
            $parsed = Carbon::parse((string) $value);
        } catch (InvalidFormatException $e) {
            throw new InvalidArgumentException('Invalid date/datetime filter value.', previous: $e);
        }

        return $withTime
            ? $parsed->format('Y-m-d H:i:s')
            : $parsed->format('Y-m-d');
    }

    private function castBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
