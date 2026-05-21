<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModelApiService
{
    public function __construct(
        private ModelRegistryService $registryService,
        private ModelFieldFormatter $fieldFormatter = new ModelFieldFormatter(),
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

        return $this->formatResponse($model, $alias);
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

        // всегда через пагинацию
        if (! $ids) {
            return $this->readPaginated($query, $alias, $params['pagination'] ?? []);
        }

        $results = $query->get();

        return [
            'data' => $this->formatMultipleResponse($results, $alias),
        ];
    }

    /**
     * @param  array<string, mixed>  $paginationParams
     * @return array{pagination: array<string, int|null>, data: list<array<string, array{value: mixed, display_text: string|null}>>}
     */
    private function readPaginated($query, string $alias, array $paginationParams): array
    {
        $defaultPerPage = max(1, (int) config('model-contracting.default_per_page', 10));
        $maxPerPage = max($defaultPerPage, (int) config('model-contracting.max_per_page', 100));

        $page = max(1, (int) ($paginationParams['page'] ?? 1));
        $perPage = (int) ($paginationParams['perPage'] ?? $defaultPerPage);
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }
        $perPage = min($perPage, $maxPerPage);

        $withTotal = array_key_exists('withTotal', $paginationParams)
            ? filter_var($paginationParams['withTotal'], FILTER_VALIDATE_BOOLEAN)
            : (bool) config('model-contracting.pagination_with_total', true);

        if ($withTotal) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'totalRecords' => $paginator->total(),
                    'totalPages' => $paginator->lastPage(),
                ],
                'data' => $this->formatMultipleResponse($paginator->items(), $alias),
            ];
        }

        $items = $query->forPage($page, $perPage)->get();

        return [
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'totalRecords' => null,
                'totalPages' => null,
            ],
            'data' => $this->formatMultipleResponse($items, $alias),
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

        $editableData = $this->filterEditableData($resource, $data);

        // Обновление записей
        $models = $modelClass::whereIn('id', $ids)->get();

        foreach ($models as $model) {
            foreach ($editableData as $field => $value) {
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

        $uniqueIds = array_values(array_unique($ids));
        $foundCount = $modelClass::whereIn('id', $uniqueIds)->count();
        if ($foundCount !== count($uniqueIds)) {
            throw new NotFoundHttpException('Record not found');
        }

        $modelClass::destroy($uniqueIds);
    }

    /**
     * Только поля с is_editable: true из контрактного ресурса.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function filterEditableData(array $resource, array $data): array
    {
        $fields = $resource['fields'] ?? [];
        $out = [];
        foreach ($data as $field => $value) {
            $cfg = $fields[$field] ?? null;
            if (is_array($cfg) && ($cfg['is_editable'] ?? false)) {
                $out[$field] = $value;
            }
        }

        return $out;
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

        if (!$isCreate && array_key_exists('id', $data)) {
            $idConfig = $resource['fields']['id'] ?? null;
            if (!is_array($idConfig) || !($idConfig['is_editable'] ?? false)) {
                throw ValidationException::withMessages([
                    'id' => ["Field 'id' is not editable"],
                ]);
            }
        }

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

            $arrayItemRules = $this->getArrayItemRules($fieldConfig);
            if ($arrayItemRules !== null) {
                $rules[$fieldName . '.*'] = $arrayItemRules;
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
    private function formatResponse(Model $model, string $alias): array
    {
        return [
            'data' => $this->formatModel($model, $alias),
        ];
    }

    /**
     * Форматирование ответа для запросов с несколькики записями модели
     * @param  iterable<int, Model>  $models
     * @return list<array<string, array{value: mixed, display_text: string|null}>>
     */
    private function formatMultipleResponse(iterable $models, string $alias): array
    {
        $resource = $this->registryService->getResourceByAlias($alias);
        $fields = $resource['fields'] ?? [];
        $fieldNames = $this->resolveFieldNames($fields, null);
        $context = $this->fieldFormatter->prepareFieldsContext($fields);

        $result = [];

        foreach ($models as $model) {
            $result[] = $this->formatModelWithContext($model, $fieldNames, $fields, $context);
        }

        return $result;
    }

    /**
     * Форматирование данных модели в массив { value, display_text } по полям контрактинга.
     *
     * @return array<string, array{value: mixed, display_text: string|null}>
     */
    private function formatModel(Model $model, string $alias): array
    {
        $resource = $this->registryService->getResourceByAlias($alias);
        $fields = $resource['fields'] ?? [];

        return $this->formatModelWithContext(
            $model,
            $this->resolveFieldNames($fields, $model->getAttributes()),
            $fields,
            $this->fieldFormatter->prepareFieldsContext($fields),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>|null  $attributes
     * @return list<string>
     */
    private function resolveFieldNames(array $fields, ?array $attributes): array
    {
        $fieldNames = array_keys($fields);
        if ($fieldNames === [] && is_array($attributes)) {
            $fieldNames = array_keys($attributes);
        }

        if (! in_array('id', $fieldNames, true)) {
            array_unshift($fieldNames, 'id');
        }

        return $fieldNames;
    }

    /**
     * @param  list<string>  $fieldNames
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array{titles: array<string, string|null>, selectMaps: array<string, array<string, string>>, enumMaps: array<string, array<string, string>>}  $context
     * @return array<string, array{value: mixed, display_text: string|null}>
     */
    private function formatModelWithContext(
        Model $model,
        array $fieldNames,
        array $fields,
        array $context,
    ): array {
        $attributes = $model->getAttributes();
        $formattedData = [];

        foreach ($fieldNames as $fieldName) {
            $fieldConfig = $fields[$fieldName] ?? [
                'name' => $fieldName,
                'title' => $fieldName,
                'type' => 'string',
            ];

            $rawValue = $attributes[$fieldName] ?? null;

            $formattedData[$fieldName] = $this->fieldFormatter->formatField(
                $rawValue,
                $fieldConfig,
                $fieldName,
                $context,
            );
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
            'extensions', 'json' => ['array'],
            'select' => $this->buildSelectRules($fieldConfig),
            'model_element_select' => $this->buildModelElementSelectRules($fieldConfig),
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

    /**
     * Статический список (select): одно значение или массив при multiple.
     *
     * @return array<int, string|object>
     */
    private function buildSelectRules(array $fieldConfig): array
    {
        $multiple = $fieldConfig['multiple'] ?? false;
        $values = $this->extractSelectOptionValues($fieldConfig);

        if ($multiple) {
            return ['array'];
        }

        if ($values !== []) {
            return [Rule::in($values)];
        }

        return ['string'];
    }

    /**
     * Выбор строки другой модели по API.
     *
     * @return array<int, string>
     */
    private function buildModelElementSelectRules(array $fieldConfig): array
    {
        $multiple = $fieldConfig['multiple'] ?? false;

        if ($multiple) {
            return ['array'];
        }

        return ['string'];
    }

    /**
     * Значения option.value из конфигурации поля type=select.
     *
     * @return list<string|int|float>
     */
    private function extractSelectOptionValues(array $fieldConfig): array
    {
        $options = $fieldConfig['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    /**
     * Правила для элементов массива.
     *
     * @return array<int, string|object>|null
     */
    private function getArrayItemRules(array $fieldConfig): ?array
    {
        $type = $fieldConfig['type'] ?? 'string';

        if ($type === 'select' && ($fieldConfig['multiple'] ?? false)) {
            $values = $this->extractSelectOptionValues($fieldConfig);

            return $values !== [] ? [Rule::in($values)] : ['string'];
        }

        if ($type === 'model_element_select' && ($fieldConfig['multiple'] ?? false)) {
            return ['string'];
        }

        $simple = match ($type) {
            'string[]' => 'string',
            'integer[]' => 'integer',
            'float[]' => 'numeric',
            'boolean[]' => 'boolean',
            default => null,
        };

        return $simple !== null ? [$simple] : null;
    }
}
