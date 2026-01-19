<?php

namespace MIIM\ModelContracting\Services;

class ModelMetaService
{
    public function __construct(
        private ModelRegistryService $registryService
    ) {}

    public function getModelMetadata(string $alias): array
    {
        $resource = $this->registryService->getResourceByAlias($alias);

        if (!$resource) {
            throw new \Exception("Model with alias '{$alias}' not found");
        }

        $fields = [];
        foreach ($resource['fields'] as $fieldName => $fieldConfig) {
            $fields[] = [
                'name' => $fieldName,
                'type' => $fieldConfig['type'],
                'default_value' => $fieldConfig['default_value'],
                'validations' => [
                    'is_required' => $fieldConfig['validations']['is_required']
                ],
                'is_selected_dict' => $fieldConfig['is_selected_dict'],
                'selected_dict_id' => $fieldConfig['selected_dict_id'],
                'is_sortable' => $fieldConfig['is_sortable'],
                'is_filtered' => $fieldConfig['is_filtered'],
                'is_FK' => $fieldConfig['is_FK'],
                'FK' => $fieldConfig['FK']
            ];
        }

        return [
            'model_alias' => $alias,
            'model_class' => $resource['model_class'],
            'fields' => $fields,
            'relationships' => $resource['relationships']
        ];
    }

    public function getFieldMetadata(string $alias, string $fieldName): ?array
    {
        $fieldConfig = $this->registryService->getFieldConfig($alias, $fieldName);

        if (!$fieldConfig) {
            return null;
        }

        return [
            'name' => $fieldName,
            'type' => $fieldConfig['type'],
            'default_value' => $fieldConfig['default_value'],
            'validations' => [
                'is_required' => $fieldConfig['validations']['is_required']
            ],
            'is_selected_dict' => $fieldConfig['is_selected_dict'],
            'selected_dict_id' => $fieldConfig['selected_dict_id'],
            'is_sortable' => $fieldConfig['is_sortable'],
            'is_filtered' => $fieldConfig['is_filtered'],
            'is_FK' => $fieldConfig['is_FK'],
            'FK' => $fieldConfig['FK']
        ];
    }
}
