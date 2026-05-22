<?php

namespace MIIM\ModelContracting\Services;

final class ModelFilterOperator
{
    public const EQ = 'eq';
    public const NEQ = 'neq';
    public const GT = 'gt';
    public const GTE = 'gte';
    public const LT = 'lt';
    public const LTE = 'lte';
    public const CONTAINS = 'contains';
    public const STARTS_WITH = 'starts_with';
    public const ENDS_WITH = 'ends_with';
    public const BETWEEN = 'between';
    public const NOT_BETWEEN = 'not_between';
    public const IN = 'in';
    public const NOT_IN = 'not_in';
    public const IS_NULL = 'is_null';

    /** @var list<string> */
    public const ALL = [
        self::EQ,
        self::NEQ,
        self::GT,
        self::GTE,
        self::LT,
        self::LTE,
        self::CONTAINS,
        self::STARTS_WITH,
        self::ENDS_WITH,
        self::BETWEEN,
        self::NOT_BETWEEN,
        self::IN,
        self::NOT_IN,
        self::IS_NULL,
    ];

    /**
     * @return list<string>
     */
    public static function allowedForType(string $type): array
    {
        return match ($type) {
            'integer', 'float' => [
                self::EQ, self::NEQ, self::GT, self::GTE, self::LT, self::LTE,
                self::BETWEEN, self::NOT_BETWEEN, self::IN, self::NOT_IN, self::IS_NULL,
            ],
            'date', 'datetime' => [
                self::EQ, self::NEQ, self::GT, self::GTE, self::LT, self::LTE,
                self::BETWEEN, self::NOT_BETWEEN, self::IN, self::NOT_IN, self::IS_NULL,
            ],
            'boolean' => [self::EQ, self::NEQ, self::IS_NULL],
            'string', 'text', 'select', 'enum', 'model_element_select' => [
                self::EQ, self::NEQ, self::CONTAINS, self::STARTS_WITH, self::ENDS_WITH,
                self::IN, self::NOT_IN, self::IS_NULL,
            ],
            'extensions', 'json' => [self::EQ, self::NEQ, self::IS_NULL],
            default => [self::EQ, self::NEQ, self::IS_NULL],
        };
    }
}
