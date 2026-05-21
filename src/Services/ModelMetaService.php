<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Support\Str;

/**
 * Мета полей.
 *
 * Типы:
 * - enum — фиксированный список в enum_list (value/text/order).
 * - select — статические options [{ name, value }, ...]; значение поля — одно value или массив value при multiple: true.
 * - model_element_select — ссылка на сущность другой модели.
 * - extensions — динамические атрибуты [{ type, name, value }, ...].
 */
class ModelMetaService
{
    public function __construct(
        private ModelRegistryService $registryService
    ) {}

    /**
     * Получение методанных по модели
     * @param string $alias алиас модели
     * @return array
     * @throws \Exception
     */
    public function getModelMetadata(string $alias): array
    {
        $resource = $this->registryService->getResourceByAlias($alias);

        if (!$resource) {
            throw new \Exception("Модель с алиасом '{$alias}' не найдена");
        }

        $fields = [];
        foreach ($resource['fields'] as $fieldName => $fieldConfig) {
            $fieldMeta = [
                'title' => $fieldConfig['title'] ?? $this->generateFieldTitle($fieldName),
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

            if (($fieldConfig['type'] ?? null) === 'enum') {
                $fieldMeta['enum_list'] = $fieldConfig['enum_list'] ?? [];
            }

            if (($fieldConfig['type'] ?? null) === 'select') {
                $fieldMeta['options'] = $fieldConfig['options'] ?? [];
                $fieldMeta['multiple'] = $fieldConfig['multiple'] ?? false;
            }

            if (($fieldConfig['type'] ?? null) === 'model_element_select') {
                $fieldMeta['search_attributes'] = $fieldConfig['search_attributes'] ?? [];
                $fieldMeta['multiple'] = $fieldConfig['multiple'] ?? false;
            }

            $fields[] = $fieldMeta;
        }

        return [
            'is_deletable' => $resource['is_deletable'] ?? true,
            'is_editable' => $resource['is_editable'] ?? true,
            'model_alias' => $alias,
            'model_class' => $resource['model_class'],
            'fields' => $fields,
        ];
    }

    /**
     * Генерация заголовков для полей
     * @param string $fieldName имя поля
     * @return string
     */
    private function generateFieldTitle(string $fieldName): string
    {
        // Преобразуем snake_case для заголовка
        $words = explode('_', $fieldName);
        $words = array_map('ucfirst', $words);

        // Специальные случаи
        $specialCases = [
            'id' => 'Идентификатор',
            'created_at' => 'Дата создания',
            'updated_at' => 'Дата обновления',
        ];

        if (isset($specialCases[$fieldName])) {
            return $specialCases[$fieldName];
        }

        return implode(' ', $words);
    }
}
