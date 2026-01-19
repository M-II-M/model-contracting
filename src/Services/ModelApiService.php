<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ModelApiService
{
    public function __construct(
        private ModelMetaService $metaService,
        private ModelRegistryService $registryService
    ) {}

    public function create(string $alias, array $data): array
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);

        if (!$modelClass) {
            throw new \Exception("Model with alias '{$alias}' not found");
        }

        // Валидация данных
        $this->validateData($alias, $data, $modelClass);

        // Создание модели
        $model = new $modelClass();

        foreach ($data as $field => $value) {
            $model->$field = $value;
        }

        $model->save();

        return $this->formatResponse($model);
    }

    public function read(string $alias, ?array $ids = null, array $params = []): array
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);

        if (!$modelClass) {
            throw new \Exception("Model with alias '{$alias}' not found");
        }

        $query = $modelClass::query();

        // Фильтрация по ID
        if ($ids) {
            $query->whereIn('id', $ids);
        }

        // Применение фильтров
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

        // Пагинация
        if (isset($params['pagination']) && is_array($params['pagination'])) {
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
                'data' => $paginator->items(),
            ];
        }

        // Если нет пагинации, возвращаем все записи
        $results = $query->get();

        return [
            'data' => $results->toArray()
        ];
    }

    public function update(string $alias, array $ids, array $data): void
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);

        if (!$modelClass) {
            throw new \Exception("Model with alias '{$alias}' not found");
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

    public function delete(string $alias, array $ids): void
    {
        $modelClass = $this->registryService->getModelClassByAlias($alias);

        if (!$modelClass) {
            throw new \Exception("Model with alias '{$alias}' not found");
        }

        $modelClass::destroy($ids);
    }

    private function validateData(string $alias, array $data, string $modelClass, bool $isCreate = true): void
    {
        $resource = $this->registryService->getResourceByAlias($alias);
        $rules = [];

        foreach ($resource['fields'] as $fieldName => $fieldConfig) {
            if (isset($data[$fieldName]) || $isCreate) {
                $fieldRules = [];

                if ($isCreate && $fieldConfig['validations']['is_required']) {
                    $fieldRules[] = 'required';
                } else {
                    $fieldRules[] = 'sometimes';
                }

                switch ($fieldConfig['type']) {
                    case 'integer':
                        $fieldRules[] = 'integer';
                        break;
                    case 'float':
                        $fieldRules[] = 'numeric';
                        break;
                    case 'boolean':
                        $fieldRules[] = 'boolean';
                        break;
                    case 'date':
                        $fieldRules[] = 'date';
                        break;
                    case 'json':
                        $fieldRules[] = 'array';
                        break;
                }

                $rules[$fieldName] = $fieldRules;
            }
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function formatResponse(Model $model): array
    {
        return [
            'data' => array_merge(
                [
                    'id' => $model->id,
                    'created_at' => $model->created_at?->format('d.m.Y H:i'),
                    'updated_at' => $model->updated_at?->format('d.m.Y H:i'),
                ],
                $model->toArray()
            )
        ];
    }
}
