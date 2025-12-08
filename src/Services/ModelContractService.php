<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MIIM\ModelContracting\Exceptions\ModelContractException;

class ModelContractService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('model-contracting', []);
    }

    /**
     * Получить метаданные модели по алиасу
     */
    public function getModelMeta(string $alias): array
    {
        $modelConfig = $this->getModelConfig($alias);

        $meta = [
            'is_deletable' => $modelConfig['is_deletable'] ?? false,
            'is_editable' => $modelConfig['is_editable'] ?? true,
            'fields' => [],
        ];

        foreach ($modelConfig['fields'] as $fieldName => $fieldConfig) {
            $fieldMeta = $this->prepareFieldMeta($fieldName, $fieldConfig);
            $meta['fields'][] = $fieldMeta;
        }

        return $meta;
    }

    /**
     * Подготовить метаданные поля
     */
    protected function prepareFieldMeta(string $fieldName, array $fieldConfig): array
    {
        $defaults = $this->config['defaults']['field'] ?? [];
        $config = array_merge($defaults, $fieldConfig);

        $fieldMeta = [
            'name' => $fieldName,
            'type' => $config['type'],
            'default_value' => $config['default_value'] ?? null,
            'validations' => [
                'is_required' => $config['is_required'] ?? false,
            ],
            'is_editable' => $config['is_editable'],
            'is_sortable' => $config['is_sortable'],
            'is_filtered' => $config['is_filtered'],
            'is_FK' => $config['is_FK'] ?? false,
            'FK' => null,
        ];

        // Добавляем информацию о внешнем ключе
        if ($config['is_FK'] ?? false) {
            $fieldMeta['FK'] = [
                'model_alias' => $config['FK']['model_alias'],
                'relation_type' => $config['FK']['relation_type'],
            ];
        }

        return $fieldMeta;
    }

    /**
     * Получить экземпляры модели
     */
    public function getInstances(string $alias, array $ids = [], array $params = []): array
    {
        $modelConfig = $this->getModelConfig($alias);
        $modelClass = $modelConfig['model'];

        $query = $modelClass::query();

        // Фильтрация по ID
        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        // Применение фильтров
        if (!empty($params['filter'])) {
            $this->applyFilters($query, $params['filter'], $modelConfig);
        }

        // Применение сортировки
        if (!empty($params['sort'])) {
            $this->applySorting($query, $params['sort'], $modelConfig);
        }

        // Пагинация
        $perPage = $params['pagination']['per_page'] ??
            $this->config['pagination']['default_per_page'] ?? 15;
        $perPage = min($perPage, $this->config['pagination']['max_per_page'] ?? 100);

        $page = $params['pagination']['page'] ?? 1;

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
            'data' => $paginator->items(),
        ];
    }

    /**
     * Создать экземпляр модели
     */
    public function createInstance(string $alias, array $data): Model
    {
        $modelConfig = $this->getModelConfig($alias);
        $modelClass = $modelConfig['model'];

        // Валидация данных
        $this->validateData($data, $modelConfig, true);

        // Преобразование данных
        $processedData = $this->processData($data, $modelConfig);

        // Создание модели
        $instance = $modelClass::create($processedData);

        return $instance;
    }

    /**
     * Обновить экземпляры модели
     */
    public function updateInstances(string $alias, array $ids, array $data): void
    {
        $modelConfig = $this->getModelConfig($alias);
        $modelClass = $modelConfig['model'];

        // Проверка возможности редактирования
        if (!($modelConfig['is_editable'] ?? true)) {
            throw new \Exception("Модель не поддерживает редактирование");
        }

        // Валидация данных (только редактируемые поля)
        $this->validateData($data, $modelConfig, false);

        // Проверка, что редактируются только разрешенные поля
        $this->checkEditableFields($data, $modelConfig);

        // Преобразование данных
        $processedData = $this->processData($data, $modelConfig);

        // Поиск моделей
        $instances = $modelClass::whereIn('id', $ids)->get();

        if ($instances->isEmpty()) {
            throw new \Exception("Экземпляры не найдены");
        }

        // Массовое обновление
        foreach ($instances as $instance) {
            $instance->update($processedData);
        }
    }

    /**
     * Удалить экземпляры модели
     */
    public function deleteInstances(string $alias, array $ids): void
    {
        $modelConfig = $this->getModelConfig($alias);
        $modelClass = $modelConfig['model'];

        // Проверка возможности удаления
        if (!($modelConfig['is_deletable'] ?? false)) {
            throw new \Exception("Модель не поддерживает удаление");
        }

        $instances = $modelClass::whereIn('id', $ids)->get();

        if ($instances->isEmpty()) {
            throw new \Exception("Экземпляры не найдены");
        }

        // Использовать мягкое удаление если настроено
        $softDelete = $modelConfig['soft_delete'] ?? false;

        foreach ($instances as $instance) {
            if ($softDelete && method_exists($instance, 'delete')) {
                $instance->delete();
            } else {
                $instance->forceDelete();
            }
        }
    }

    /**
     * Парсить ID из строки
     */
    public function parseIds(?string $idsString): array
    {
        if (empty($idsString)) {
            return [];
        }

        // Поддержка формата: id=1,2,3 или id[]=1&id[]=2
        $ids = is_string($idsString) ?
            explode(',', $idsString) :
            (array) $idsString;

        return array_filter(array_map('trim', $ids));
    }

    /**
     * Подготовить параметры из запроса
     */
    public function prepareParamsFromRequest(Request $request): array
    {
        $params = [
            'pagination' => [
                'page' => (int) $request->input('pagination.page', 1),
                'per_page' => (int) $request->input('pagination.perPage',
                    $this->config['pagination']['default_per_page'] ?? 15),
            ],
            'sort' => [],
            'filter' => [],
        ];

        // Сортировка
        if ($request->has('sort.field')) {
            $params['sort'] = [
                'field' => $request->input('sort.field'),
                'order' => strtoupper($request->input('sort.order', 'ASC')),
            ];
        }

        // Фильтры
        $filters = $request->input('filter', []);
        if (is_array($filters)) {
            foreach ($filters as $field => $value) {
                if (!empty($value)) {
                    $params['filter'][$field] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Применить фильтры к запросу
     */
    protected function applyFilters(Builder $query, array $filters, array $modelConfig): void
    {
        foreach ($filters as $field => $value) {
            $fieldConfig = $modelConfig['fields'][$field] ?? null;

            if (!$fieldConfig || !($fieldConfig['is_filtered'] ?? true)) {
                continue;
            }

            $type = $fieldConfig['type'] ?? 'string';

            // Обработка разных типов фильтрации
            switch ($type) {
                case 'string':
                case 'text':
                    $query->where($field, 'LIKE', "%{$value}%");
                    break;

                case 'integer':
                case 'float':
                    // Поддержка диапазона
                    if (is_array($value) && isset($value['from'], $value['to'])) {
                        $query->whereBetween($field, [$value['from'], $value['to']]);
                    } else {
                        $query->where($field, $value);
                    }
                    break;

                case 'boolean':
                    $query->where($field, (bool) $value);
                    break;

                default:
                    $query->where($field, $value);
            }
        }
    }

    /**
     * Применить сортировку
     */
    protected function applySorting(Builder $query, array $sort, array $modelConfig): void
    {
        $field = $sort['field'];
        $order = $sort['order'] ?? 'ASC';

        $fieldConfig = $modelConfig['fields'][$field] ?? null;

        if ($fieldConfig && ($fieldConfig['is_sortable'] ?? true)) {
            $query->orderBy($field, $order);
        }
    }

    /**
     * Валидация данных
     */
    protected function validateData(array $data, array $modelConfig, bool $isCreation): void
    {
        $rules = [];
        $messages = [];

        foreach ($modelConfig['fields'] as $field => $config) {
            // Для обновления проверяем только если поле присутствует
            if (!$isCreation && !array_key_exists($field, $data)) {
                continue;
            }

            $fieldRules = [];

            // Проверка обязательности
            if ($config['is_required'] ?? false) {
                if ($isCreation) {
                    $fieldRules[] = 'required';
                } else {
                    $fieldRules[] = 'sometimes';
                    $fieldRules[] = 'required';
                }
            } else {
                $fieldRules[] = 'nullable';
            }

            // Проверка типа
            switch ($config['type']) {
                case 'string':
                    $fieldRules[] = 'string';
                    $fieldRules[] = 'max:255';
                    break;

                case 'text':
                    $fieldRules[] = 'string';
                    break;

                case 'integer':
                    $fieldRules[] = 'integer';
                    break;

                case 'float':
                    $fieldRules[] = 'numeric';
                    break;

                case 'boolean':
                    $fieldRules[] = 'boolean';
                    break;

                case 'string[]':
                    $fieldRules[] = 'array';
                    $fieldRules[] = function ($attribute, $value, $fail) {
                        if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
                            $fail('Все элементы массива должны быть строками.');
                        }
                    };
                    break;

                case 'integer[]':
                    $fieldRules[] = 'array';
                    $fieldRules[] = function ($attribute, $value, $fail) {
                        if (!is_array($value) || array_filter($value, 'is_int') !== $value) {
                            $fail('Все элементы массива должны быть целыми числами.');
                        }
                    };
                    break;

                case 'boolean[]':
                    $fieldRules[] = 'array';
                    $fieldRules[] = function ($attribute, $value, $fail) {
                        if (!is_array($value) || array_filter($value, 'is_bool') !== $value) {
                            $fail('Все элементы массива должны быть булевыми значениями.');
                        }
                    };
                    break;
            }

            if (!empty($fieldRules)) {
                $rules[$field] = $fieldRules;
            }
        }

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
    }

    /**
     * Проверить редактируемость полей
     */
    protected function checkEditableFields(array $data, array $modelConfig): void
    {
        foreach ($data as $field => $value) {
            $config = $modelConfig['fields'][$field] ?? null;

            if (!$config) {
                throw new \Exception("Поле {$field} не существует в модели");
            }

            if (!($config['is_editable'] ?? true)) {
                throw new \Exception("Поле {$field} не редактируемое");
            }
        }
    }

    /**
     * Обработать данные (привести типы)
     */
    protected function processData(array $data, array $modelConfig): array
    {
        $processed = [];

        foreach ($data as $field => $value) {
            $config = $modelConfig['fields'][$field] ?? null;

            if (!$config) {
                continue;
            }

            // Приведение типов
            $processed[$field] = $this->castValue($value, $config['type']);
        }

        return $processed;
    }

    /**
     * Привести значение к нужному типу
     */
    protected function castValue($value, string $type)
    {
        if (is_null($value)) {
            return null;
        }

        switch ($type) {
            case 'integer':
                return (int) $value;

            case 'float':
                return (float) $value;

            case 'boolean':
                return (bool) $value;

            case 'string[]':
                return is_array($value) ? $value : [$value];

            case 'integer[]':
                return array_map('intval', (array) $value);

            case 'float[]':
                return array_map('floatval', (array) $value);

            case 'boolean[]':
                return array_map('boolval', (array) $value);

            case 'string':
            case 'text':
            default:
                return $value;
        }
    }

    /**
     * Получить конфигурацию модели
     */
    protected function getModelConfig(string $alias): array
    {
        $models = $this->config['models'] ?? [];

        if (!isset($models[$alias])) {
            throw new \Exception("Модель с алиасом {$alias} не найдена");
        }

        return $models[$alias];
    }
}
