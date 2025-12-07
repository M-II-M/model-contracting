<?php

namespace MIIM\ModelContracting\Traits;

trait HasModelContract
{
    /**
     * Получить конфигурацию полей модели для API
     */
    public static function getModelContractConfig(): array
    {
        $model = new static();
        $table = $model->getTable();

        $fields = [];
        $casts = $model->getCasts();

        // Анализ полей модели
        foreach ($model->getFillable() as $field) {
            $type = $casts[$field] ?? 'string';

            // Преобразование типов Eloquent в наши типы
            $apiType = match($type) {
                'int', 'integer' => 'integer',
                'float', 'double', 'decimal' => 'float',
                'bool', 'boolean' => 'boolean',
                'array', 'json' => 'string[]',
                default => 'string',
            };

            $fields[$field] = [
                'type' => $apiType,
                'default_value' => $model->getAttribute($field) ?? null,
                'validations' => [
                    'is_required' => in_array($field, $model->getGuarded()),
                ],
                'is_editable' => true,
                'is_sortable' => true,
                'is_filtered' => true,
                'is_FK' => false,
                'FK' => null,
            ];
        }

        // Добавляем системные поля
        if ($model->getKeyName()) {
            $fields[$model->getKeyName()] = [
                'type' => 'integer',
                'default_value' => null,
                'validations' => ['is_required' => false],
                'is_editable' => false,
                'is_sortable' => false,
                'is_filtered' => false,
                'is_FK' => false,
                'FK' => null,
            ];
        }

        if ($model->usesTimestamps()) {
            $fields['created_at'] = [
                'type' => 'string',
                'default_value' => null,
                'validations' => ['is_required' => false],
                'is_editable' => false,
                'is_sortable' => true,
                'is_filtered' => false,
                'is_FK' => false,
                'FK' => null,
            ];

            $fields['updated_at'] = [
                'type' => 'string',
                'default_value' => null,
                'validations' => ['is_required' => false],
                'is_editable' => false,
                'is_sortable' => true,
                'is_filtered' => false,
                'is_FK' => false,
                'FK' => null,
            ];
        }

        return $fields;
    }

    /**
     * Получить атрибуты по умолчанию
     */
    public function getDefaultAttributes(): array
    {
        return [];
    }
}
