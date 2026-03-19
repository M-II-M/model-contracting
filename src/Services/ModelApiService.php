<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ModelApiService
{
    public function __construct(
        private ModelRegistryService $registryService
    ) {}

    /**
     * Созадние записи в модели
     * @param string $alias алиас модели
     * @param array $data данные для создания записи
     * @return array[]
     * @throws \Exception
     */
    public function create(string $alias, array $data): array
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);

        $this->isModelWithAliasExist($modelClass, $alias);

        $this->validateData($alias, $data, $modelClass);

        $model = new $modelClass();

        foreach ($data as $field => $value) {
            $model->$field = $value;
        }

        $model->save();

        return $this->formatResponse($model);
    }

    /**
     * Получение данных модели c пагинацей:
     * - получения конкретных записей по id
     * - получение с фильтрацией
     * - получение с сортировкой
     * @param string $alias алиас модели
     * @param array|null $ids массив с id для выборки
     * @param array $params параметры запроса (фильтры, сортировка, пагинация)
     * @return array|array[]
     * @throws \Exception
     */
    public function read(string $alias, ?array $ids = null, array $params = []): array
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);

        $this->isModelWithAliasExist($modelClass, $alias);

        $query = $modelClass::query();

        // Фильтрация по ID
        if ($ids) {
            $query->whereIn('id', $ids);
        }

        // Фильтрация
        if (isset($params['filter']) && is_array($params['filter'])) {
            $filterableFields = $this->registryService->getFilterableFields($alias);

            foreach ($params['filter'] as $field => $value) {
                if (isset($filterableFields[$field])) {
                    $query->where($field, $value);
                }
            }
        }

        // Сортировка
        if (isset($params['sort']) && is_array($params['sort'])) {
            $sortableFields = $this->registryService->getSortableFields($alias);

            if (isset($params['sort']['field']) && isset($sortableFields[$params['sort']['field']])) {
                $order = $params['sort']['order'] ?? 'ASC';
                $query->orderBy($params['sort']['field'], $order);
            }
        }

        // Пагинация (только для просмотра датасета, без конкретных id)
        if (!$ids && isset($params['pagination']) && is_array($params['pagination'])) {
            $page = $params['pagination']['page'] ?? 1;
            $perPage = $params['pagination']['perPage'] ?? 10;

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'totalRecords' => $paginator->total(),
                    'totalPages' => $paginator->lastPage(),
                ],
                'data' => $this->formatMultipleResponse($paginator->items()),
            ];
        }

        $results = $query->get();

        return [
            'data' => $this->formatMultipleResponse($results)
        ];
    }

    /**
     * Обновление одной/нескольких записей модели
     * @param string $alias алиас модели
     * @param array $ids массив id записей для обновления
     * @param array $data массив данных для обновления
     * @return void
     * @throws \Exception
     */
    public function update(string $alias, array $ids, array $data): void
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);
        $resource = $this->registryService->getResourceByAlias($alias);

        $this->isModelWithAliasExist($modelClass, $alias);
        if (($resource['is_editable'] ?? true) === false) {
            throw new \Exception("Модель с алиасом '{$alias}' недоступна для редактирования");
        }

        // Валидация данных для обновления
        $this->validateData($alias, $data, $modelClass, false);

        // Обновление записей
        $models = $modelClass::whereIn('id', $ids)->get();

        foreach ($models as $model) {
            foreach ($data as $field => $value) {
                $model->$field = $value;
            }
            $model->save();
        }
    }

    /**
     * Удаление одной/нескольких записей модели
     * @param string $alias алиас модели
     * @param array $ids массив id записей для удаления
     * @return void
     * @throws \Exception
     */
    public function delete(string $alias, array $ids): void
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);
        $resource = $this->registryService->getResourceByAlias($alias);

        $this->isModelWithAliasExist($modelClass, $alias);
        if (($resource['is_deletable'] ?? true) === false) {
            throw new \Exception("Модель с алиасом '{$alias}' недоступна для удаления");
        }

        $modelClass::destroy($ids);
    }

    /**
     * Построение валидации для конкретной модели
     * @param string $alias алиас модели
     * @param array $data данные для валидации
     * @param bool $isCreate используются ли данные для создания записи в модели
     * @return void
     */
    private function validateData(string $alias, array $data, string $modelClass, bool $isCreate = true): void
    {
        $resource = $this->registryService->getResourceByAlias($alias);

        // Получаем модель для имени таблицы
        $modelInstance = app($modelClass);
        $tableName = $modelInstance->getTable();

        // Построение правил валидации под конкретную модель
        $rules = [];
        foreach ($resource['fields'] as $fieldName => $fieldConfig) {
            if ($fieldName === 'id') {
                continue;
            }

            if (!$isCreate && isset($data[$fieldName]) && !($fieldConfig['is_editable'] ?? false)) {
                throw ValidationException::withMessages([
                    $fieldName => ["Field '{$fieldName}' is not editable"],
                ]);
            }
            if ($isCreate) {
                // При создании: валидируем ВСЕ обязательные поля
                // и те необязательные поля, которые переданы
                $shouldValidate = $fieldConfig['validations']['is_required'] || isset($data[$fieldName]);
            } else {
                // При обновлении: валидируем ТОЛЬКО те поля, которые переданы в data
                $shouldValidate = isset($data[$fieldName]);
            }

            if (!$shouldValidate) {
                continue;
            }

            $fieldRules = [];

            if ($isCreate && $fieldConfig['validations']['is_required']) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'sometimes';
            }

            // Добавляем проверку уникальности если указано в ресурсе
            if (isset($fieldConfig['validations']['is_unique']) && $fieldConfig['validations']['is_unique']) {
                $fieldRules[] = 'unique:' . $tableName . ',' . $fieldName;
            }

            $typeRules = $this->buildTypeRules($fieldConfig);
            $fieldRules = array_merge($fieldRules, $typeRules);

            $rules[$fieldName] = $fieldRules;

            $arrayItemRule = $this->getArrayItemRule($fieldConfig['type'] ?? 'string');
            if ($arrayItemRule !== null) {
                $rules[$fieldName . '.*'] = [$arrayItemRule];
            }
        }

        if (!empty($rules)) {
            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }
    }

    /**
     * Форматирование ответа для запросов
     * @param Model $model модель
     * @return array[]
     */
    private function formatResponse(Model $model): array
    {
        return [
            'data' => $this->formatModel($model)
        ];
    }

    /**
     * Форматирование ответа для запросов с несколькики записями модели
     * @param $models
     * @return array
     */
    private function formatMultipleResponse($models): array
    {
        $result = [];

        foreach ($models as $model) {
            $result[] = $this->formatModel($model);
        }

        return $result;
    }

    /**
     * Форматирование данных модели в массив
     * @param Model $model модель
     * @return array
     */
    private function formatModel(Model $model): array
    {
        $data = $model->toArray();

        $formattedData = ['id' => $model->id];

        foreach ($data as $key => $value) {
            if ($key !== 'id') {
                $formattedData[$key] = $value;
            }
        }

        return $formattedData;
    }

    private function isModelWithAliasExist(?string $modelClass, string $alias): void
    {
        if (!$modelClass) {
            throw new \Exception("Модель с алиасом '{$alias}' не найдена");
        }
    }

    /**
     * Построение правил валидации.
     */
    private function buildTypeRules(array $fieldConfig): array
    {
        $type = $fieldConfig['type'] ?? 'string';

        $rules = match ($type) {
            'integer' => ['integer'],
            'float' => ['numeric'],
            'boolean' => ['boolean'],
            'date' => ['date_format:Y-m-d'],
            'datetime' => ['date_format:Y-m-d H:i:s'],
            'string[]' => ['array'],
            'integer[]' => ['array'],
            'float[]' => ['array'],
            'boolean[]' => ['array'],
            'json' => ['array'],
            default => ['string'],
        };

        if ($type === 'enum' && isset($fieldConfig['enum_list']) && is_array($fieldConfig['enum_list'])) {
            $enumValues = array_values(array_filter(
                array_map(
                    static fn ($item) => is_array($item) ? ($item['value'] ?? null) : null,
                    $fieldConfig['enum_list']
                ),
                static fn ($value) => $value !== null
            ));

            if (!empty($enumValues)) {
                $rules[] = 'in:' . implode(',', $enumValues);
            }
        }

        return $rules;
    }

    private function getArrayItemRule(string $type): ?string
    {
        return match ($type) {
            'string[]' => 'string',
            'integer[]' => 'integer',
            'float[]' => 'numeric',
            'boolean[]' => 'boolean',
            default => null,
        };
    }
}
