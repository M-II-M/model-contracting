<?php

namespace MIIM\ModelContracting\Services;

class ModelRegistryService
{
    private array $registeredResources = [];

    public function register(string $resourceClass): void
    {
        $modelClass = $resourceClass::getModelClass();
        $modelAlias = $resourceClass::getModelAlias();

        $this->registeredResources[$modelAlias] = [
            'resource_class' => $resourceClass,
            'model_class' => $modelClass,
            'fields' => $resourceClass::getFields(),
            'relationships' => $resourceClass::getRelationships(),
        ];
    }

    public function getResourceByAlias(string $alias): ?array
    {
        return $this->registeredResources[$alias] ?? null;
    }

    public function getModelClassByAlias(string $alias): ?string
    {
        return $this->registeredResources[$alias]['model_class'] ?? null;
    }

    public function getResourceClassByAlias(string $alias): ?string
    {
        return $this->registeredResources[$alias]['resource_class'] ?? null;
    }

    public function getAllRegisteredModels(): array
    {
        return array_keys($this->registeredResources);
    }

    public function getFieldConfig(string $alias, string $fieldName): ?array
    {
        $resource = $this->getResourceByAlias($alias);
        return $resource['fields'][$fieldName] ?? null;
    }

    public function updateFieldConfig(string $alias, string $fieldName, array $config): bool
    {
        $resourceClass = $this->getResourceClassByAlias($alias);

        if (!$resourceClass || !method_exists($resourceClass, 'updateField')) {
            return false;
        }

        $resourceClass::updateField($fieldName, $config);
        $this->registeredResources[$alias]['fields'][$fieldName] = array_merge(
            $this->registeredResources[$alias]['fields'][$fieldName] ?? [],
            $config
        );

        return true;
    }

    public function getSortableFields(string $alias): array
    {
        $resource = $this->getResourceByAlias($alias);
        if (!$resource) return [];

        return array_filter($resource['fields'], fn($field) => $field['is_sortable'] ?? true);
    }

    public function getFilterableFields(string $alias): array
    {
        $resource = $this->getResourceByAlias($alias);
        if (!$resource) return [];

        return array_filter($resource['fields'], fn($field) => $field['is_filtered'] ?? true);
    }

    public function getEditableFields(string $alias): array
    {
        $resource = $this->getResourceByAlias($alias);
        if (!$resource) return [];

        return array_filter($resource['fields'], fn($field) => $field['is_editable'] ?? ($field['name'] !== 'id'));
    }

    public function getRequiredFields(string $alias): array
    {
        $resource = $this->getResourceByAlias($alias);
        if (!$resource) return [];

        return array_filter($resource['fields'], fn($field) => $field['validations']['is_required'] ?? false);
    }
}
