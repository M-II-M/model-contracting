<?php

namespace MIIM\ModelContracting\Commands;

use ReflectionClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenerateModelResourceCommand extends Command
{
    protected $signature = 'generate:model-resource {model : The model class}
                                              {--alias= : Custom model alias}';

    protected $description = 'Command description';

    private string $resourceNamespace = 'App\\ModelResources';

    public function handle()
    {
        $modelClass = $this->argument('model');
        $alias = $this->option('alias') ?: Str::snake(Str::plural(class_basename($modelClass)));

        try {
            $model = app($modelClass);
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);

            $this->info("Генеррация ресурса для модели: {$modelClass}");
            $this->info("Таблица: {$table}");
            $this->info("Алиас: {$alias}");

            $fields = $this->analyzeModelFields($modelClass, $table, $columns);
            $relationships = $this->analyzeModelRelationships($modelClass);

            $this->generateResourceClass($modelClass, $alias, $fields, $relationships);

            $this->info("Генеррация ресурса завершена");
            $this->info("API роуты для ресурса': /api/model-resources/{$alias}");
            $this->info("Класс ресурса: " . $this->getResourceClassName($modelClass));

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    private function analyzeModelFields(string $modelClass, string $table, array $columns): array
    {
        $fields = [];

        foreach ($columns as $column) {
            try {
                $columnType = DB::getSchemaBuilder()->getColumnType($table, $column);
                $defaultValue = $this->getDefaultValue($modelClass, $column);
                $isFK = Str::endsWith($column, '_id');

                // Определяем связанную модель для FK
                $fkInfo = null;
                if ($isFK) {
                    $relatedModel = $this->guessRelatedModel($modelClass, $column);
                    $relationType = $this->guessRelationType($modelClass, $column);

                    if ($relatedModel) {
                        $fkInfo = [
                            'model_alias' => Str::snake(Str::plural(class_basename($relatedModel))),
                            'relation_type' => $relationType,
                        ];
                    }
                }

                $fields[$column] = [
                    'name' => $column,
                    'type' => $this->mapColumnType($columnType),
                    'default_value' => $defaultValue,
                    'validations' => [
                        'is_required' => $this->isColumnRequired($table, $column),
                    ],
                    'is_selected_dict' => false,
                    'selected_dict_id' => null,
                    'is_sortable' => true,
                    'is_filtered' => true,
                    'is_FK' => $isFK,
                    'FK' => $fkInfo,
                ];
            } catch (\Exception $e) {
                $this->warn("Ошибка анализа столбца {$column}: " . $e->getMessage());
                continue;
            }
        }

        return $fields;
    }

    private function analyzeModelRelationships(string $modelClass): array
    {
        $relationships = [];
        $allowedRelationTypes = ['BelongsTo', 'HasOne', 'HasMany', 'BelongsToMany', 'MorphTo', 'MorphOne', 'MorphMany'];
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();

            if ($returnType && method_exists($returnType, 'getName')) {
                $returnTypeName = $returnType->getName();

                if (Str::contains($returnTypeName, $allowedRelationTypes)) {
                    $methodName = $method->getName();

                    try {
                        $relation = app($modelClass)->$methodName();
                        $relatedModel = get_class($relation->getRelated());

                        $relationships[$methodName] = [
                            'type' => class_basename($returnTypeName),
                            'related_model' => $relatedModel,
                            'foreign_key' => method_exists($relation, 'getForeignKeyName')
                                ? $relation->getForeignKeyName()
                                : null,
                            'local_key' => method_exists($relation, 'getLocalKeyName')
                                ? $relation->getLocalKeyName()
                                : null,
                        ];
                    } catch (\Exception $e) {
                        // Скип если не распознали связь
                        $this->warn("Ошибка анализа связи {$method}: " . $e->getMessage());
                        continue;
                    }
                }
            }
        }

        return $relationships;
    }

    private function guessRelatedModel(string $modelClass, string $column): ?string
    {
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();

            if ($returnType && method_exists($returnType, 'getName')) {
                $returnTypeName = $returnType->getName();

                if (Str::contains($returnTypeName, 'BelongsTo')) {
                    try {
                        $relation = app($modelClass)->$methodName();

                        if (method_exists($relation, 'getForeignKeyName') &&
                            $relation->getForeignKeyName() === $column) {
                            return get_class($relation->getRelated());
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        // Попробуем угадать по имени столбца
        $relatedModelName = Str::studly(Str::replaceLast('_id', '', $column));
        $possibleNamespaces = [
            'App\\Models\\',
            'App\\',
        ];

        foreach ($possibleNamespaces as $namespace) {
            $className = $namespace . $relatedModelName;
            if (class_exists($className)) {
                return $className;
            }
        }

        return null;
    }

    private function guessRelationType(string $modelClass, string $column): string
    {
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();

            if ($returnType && method_exists($returnType, 'getName')) {
                $returnTypeName = $returnType->getName();

                if (Str::contains($returnTypeName, 'BelongsTo')) {
                    try {
                        $relation = app($modelClass)->$methodName();

                        if (method_exists($relation, 'getForeignKeyName') &&
                            $relation->getForeignKeyName() === $column) {
                            return 'belongsTo';
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return 'belongsTo';
    }

    private function mapColumnType(string $columnType): string
    {
        $fieldTypes = [
            'string' => ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'string'],
            'integer' => ['int', 'bigint', 'mediumint', 'smallint', 'tinyint', 'integer'],
            'float' => ['float', 'double', 'decimal', 'numeric', 'real'],
            'boolean' => ['boolean', 'bool', 'tinyint(1)'],
            'date' => ['date', 'datetime', 'timestamp', 'timestamptz'],
            'json' => ['json', 'jsonb'],
        ];

        foreach ($fieldTypes as $type => $matches) {
            foreach ($matches as $match) {
                if (Str::contains($columnType, $match)) {
                    return $type;
                }
            }
        }

        return 'string';
    }

    private function getDefaultValue(string $modelClass, string $column): mixed
    {
        try {
            $model = app($modelClass);
            $casts = $model->getCasts();

            if (isset($casts[$column])) {
                switch ($casts[$column]) {
                    case 'boolean':
                    case 'bool':
                        return false;
                    case 'integer':
                    case 'int':
                        return 0;
                    case 'float':
                    case 'double':
                    case 'decimal':
                        return 0.0;
                    case 'array':
                    case 'json':
                        return [];
                    default:
                        return null;
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function isColumnRequired(string $table, string $column): bool
    {
        try {
            // Проверяем, является ли колонка nullable
            $columns = Schema::getColumns($table);

            foreach ($columns as $col) {
                if ($col['name'] === $column) {
                    return !$col['nullable'];
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->warn("Ошибка анализа столбца: " . $e->getMessage());
            return false;
        }
    }

    private function getResourceClassName(string $modelClass): string
    {
        return $this->resourceNamespace . '\\' . class_basename($modelClass) . 'ContractingResource';
    }

    private function generateResourceClass(string $modelClass, string $alias, array $fields, array $relationships): void
    {
        $className = class_basename($modelClass) . 'ContractingResource';
        $path = app_path('ModelResources');

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $stubPath = __DIR__ . '/../Stubs/ModelResource.stub';
        $stub = file_get_contents($stubPath);

        $fieldsString = $this->formatArrayForPhp($fields, 2);
        $relationshipsString = $this->formatArrayForPhp($relationships, 2);

        $replacements = [
            '{{namespace}}' => $this->resourceNamespace,
            '{{className}}' => $className,
            '{{modelClass}}' => $modelClass,
            '{{modelAlias}}' => $alias,
            '{{fields}}' => $fieldsString,
            '{{relationships}}' => $relationshipsString,
        ];

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $stub
        );

        file_put_contents($path . '/' . $className . '.php', $content);

        $this->registerResource($this->resourceNamespace . '\\' . $className);
    }

    private function formatArrayForPhp(array $array, int $indentLevel = 0): string
    {
        $indent = str_repeat('    ', $indentLevel);
        $lines = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $formattedValue = $this->formatArrayForPhp($value, $indentLevel + 1);
                $lines[] = "'{$key}' => {$formattedValue},";
            } else if (is_string($value)) {
                $lines[] = "'{$key}' => '{$value}',";
            } else if (is_bool($value)) {
                $lines[] = "'{$key}' => " . ($value ? 'true' : 'false') . ",";
            } else if (is_null($value)) {
                $lines[] = "'{$key}' => null,";
            } else {
                $lines[] = "'{$key}' => {$value},";
            }
        }

        if (empty($lines)) {
            return '[]';
        }

        return "[\n" . $indent . '    ' . implode("\n" . $indent . '    ', $lines) . "\n" . $indent . "]";
    }

    private function registerResource(string $resourceClass): void
    {
        if (!class_exists($resourceClass)) {
            require_once(config('model-resource.path', app_path('ModelResources')) .
                '/' . class_basename($resourceClass) . '.php');
        }

        $resourceClass::boot();
    }
}
