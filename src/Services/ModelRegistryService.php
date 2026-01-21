<?php

namespace MIIM\ModelContracting\Services;

class ModelRegistryService
{
    private array $registeredResources = [];

    /**
     * Регистрация ресурса в реестре
     * @param string $resourceClass класс ресурса
     * @return void
     */
    public function register(string $resourceClass): void
    {
        $modelClass = $resourceClass::getModelClass();
        $modelAlias = $resourceClass::getModelAlias();

        $this->registeredResources[$modelAlias] = [
            'resource_class' => $resourceClass,
            'model_class' => $modelClass,
            'fields' => $resourceClass::getFields(),
        ];
    }

    /**
     * Получение конфигурации модели по алиасу
     * @param string $alias алиас модели
     * @return array|null
     */
    public function getResourceByAlias(string $alias): ?array
    {
        return $this->registeredResources[$alias] ?? null;
    }

    /**
     * Получение класса модели по алиасу
     * @param string $alias алиас модели
     * @return string|null
     */
    public function getModelClassByAlias(string $alias): ?string
    {
        return $this->registeredResources[$alias]['model_class'] ?? null;
    }

    /**
     * Получегие полей модели,по которым можно сортировать
     * @param string $alias алиас модели
     * @return array
     */
    public function getSortableFields(string $alias): array
    {
        $resource = $this->getResourceByAlias($alias);
        if (!$resource) return [];

        return array_filter($resource['fields'], fn($field) => $field['is_sortable'] ?? true);
    }

    /**
     * Получение полей модели, по которым можно фильтровать
     * @param string $alias алиас модели
     * @return array
     */
    public function getFilterableFields(string $alias): array
    {
        $resource = $this->getResourceByAlias($alias);
        if (!$resource) return [];

        return array_filter($resource['fields'], fn($field) => $field['is_filtered'] ?? true);
    }
}
