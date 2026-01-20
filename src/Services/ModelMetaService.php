<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Support\Str;

class ModelMetaService
{
    public function __construct(
        private ModelRegistryService $registryService
    ) {}

    public function getModelMetadata(string $alias): array
    {
        $resource = $this->registryService->getResourceByAlias($alias);

        if (!$resource) {
            throw new \Exception("Model with alias '{$alias}' не найдена");
        }

        $fields = [];
        foreach ($resource['fields'] as $fieldName => $fieldConfig) {
            $fields[] = [
                'title' => $this->generateFieldTitle($fieldName),
                'field_name' => $fieldName,
                'type' => $fieldConfig['type'],
                'default_value' => $fieldConfig['default_value'],
                'validations' => [
                    'is_required' => $fieldConfig['validations']['is_required']
                ],
                'is_selected_dict' => $fieldConfig['is_selected_dict'],
                'selected_dict_id' => $fieldConfig['selected_dict_id'],
                'is_editable' => $fieldConfig['is_editable'] ?? ($fieldName !== 'id'),
                'is_sortable' => $fieldConfig['is_sortable'],
                'is_filtered' => $fieldConfig['is_filtered'],
                'is_FK' => $fieldConfig['is_FK'],
                'FK' => $fieldConfig['FK']
            ];
        }

        return [
            'is_deletable' => true,
            'is_editable' => true,
            'model_alias' => $alias,
            'model_class' => $resource['model_class'],
            'fields' => $fields,
        ];
    }

    public function getFieldMetadata(string $alias, string $fieldName): ?array
    {
        $fieldConfig = $this->registryService->getFieldConfig($alias, $fieldName);

        if (!$fieldConfig) {
            return null;
        }

        return [
            'title' => $this->generateFieldTitle($fieldName),
            'field_name' => $fieldName,
            'type' => $fieldConfig['type'],
            'default_value' => $fieldConfig['default_value'],
            'validations' => [
                'is_required' => $fieldConfig['validations']['is_required']
            ],
            'is_selected_dict' => $fieldConfig['is_selected_dict'],
            'selected_dict_id' => $fieldConfig['selected_dict_id'],
            'is_editable' => $fieldConfig['is_editable'] ?? ($fieldName !== 'id'),
            'is_sortable' => $fieldConfig['is_sortable'],
            'is_filtered' => $fieldConfig['is_filtered'],
            'is_FK' => $fieldConfig['is_FK'],
            'FK' => $fieldConfig['FK']
        ];
    }

    private function generateFieldTitle(string $fieldName): string
    {
        // Преобразуем snake_case в читаемый текст
        $words = explode('_', $fieldName);
        $words = array_map('ucfirst', $words);

        // Специальные случаи
        $specialCases = [
            'id' => 'Идентификатор',
            'created_at' => 'Дата создания',
            'updated_at' => 'Дата обновления',
            'deleted_at' => 'Дата удаления',
        ];

        if (isset($specialCases[$fieldName])) {
            return $specialCases[$fieldName];
        }

        return implode(' ', $words);
    }
}
