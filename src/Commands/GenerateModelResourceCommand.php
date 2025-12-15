<?php

namespace MIIM\ModelContracting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenerateModelResourceCommand extends Command
{
    protected $signature = 'generate:model-resource {model : The model class}
                                              {--alias= : Custom model alias}';

    protected $description = 'Command description';

    public function handle()
    {
        $modelClass = $this->argument('model');
        $alias = $this->option('alias') ?: Str::snake(Str::plural(class_basename($modelClass)));

        try {
            $model = app($modelClass);
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);

            $this->info("Генерация ресурса для: {$modelClass}");
            $this->info("Таблица: {$table}");
            $this->info("Алиас: {$alias}");

            $fields = $this->analyzeModelFields($modelClass, $table, $columns);
            $relationships = $this->analyzeModelRelationships($modelClass);

            $this->generateResourceClass($modelClass, $alias, $fields, $relationships);

            $this->info("Ресурс успешно создан". $this->getResourceClassName($modelClass));
        } catch (\Exception $e) {
            $this->error("Ошибка: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    private function analyzeModelFields(string $modelClass, string $table, array $columns): array
    {
        $fields = [];

        foreach ($columns as $column) {

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
        }

        return $fields;
    }

    private function analyzeModelRelationships(string $modelClass): array
    {
        $relationships = [];
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();

            if ($returnType && method_exists($returnType, 'getName')) {
                $returnTypeName = $returnType->getName();

                if (Str::contains($returnTypeName, ['BelongsTo', 'HasOne', 'HasMany', 'BelongsToMany'])) {
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
                        // Скип
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
        $fieldTypes = config('model-resource.field_types', []);

        foreach ($fieldTypes as $type => $matches) {
            foreach ($matches as $match) {
                if (Str::contains($columnType, $match)) {
                    return $type;
                }
            }
        }

        return 'string';
    }

    private function getDefaultValue(string $modelClass, string $column)
    {
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
                    return 0.0;
                case 'array':
                case 'json':
                    return [];
                default:
                    return null;
            }
        }

        return null;
    }

    private function isColumnRequired(string $table, string $column): bool
    {
        $columnInfo = DB::selectOne(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?",
            [$table, $column]
        );

        return $columnInfo && $columnInfo->IS_NULLABLE === 'NO';
    }

    private function getResourceClassName(string $modelClass): string
    {
        $namespace = config('model-resource.namespace', 'App\\ModelResources');
        $className = class_basename($modelClass) . 'ContractingResource';

        return $namespace . '\\' . $className;
    }

    private function generateResourceClass(string $modelClass, string $alias, array $fields, array $relationships): void
    {
        $className = class_basename($modelClass) . 'ContractingResource';
        $namespace = config('model-resource.namespace', 'App\\ModelResources');
        $path = config('model-resource.path', app_path('ModelResources'));

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $stubPath = __DIR__ . '/../Stubs/ModelResource.stub';
        if (!file_exists($stubPath)) {
            // Создаем stub на лету
            $stub = $this->getStubContent();
        } else {
            $stub = file_get_contents($stubPath);
        }

        $fieldsString = $this->formatArrayForPhp($fields, 2);
        $relationshipsString = $this->formatArrayForPhp($relationships, 2);

        $replacements = [
            '{{namespace}}' => $namespace,
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

        $this->registerResource($namespace . '\\' . $className);
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

        $result = "[\n" . $indent . '    ' . implode("\n" . $indent . '    ', $lines) . "\n" . $indent . "]";
        return $result;
    }

    private function getStubContent(): string
    {
        return <<<'STUB'
<?php

namespace {{namespace}};

use Vendor\ModelResourceManager\Contracts\ModelResourceInterface;
use Vendor\ModelResourceManager\Services\ModelRegistry;

class {{className}} implements ModelResourceInterface
{
    protected static string $modelClass = '{{modelClass}}';
    protected static string $modelAlias = '{{modelAlias}}';

    protected static array $fields = {{fields}};

    protected static array $relationships = {{relationships}};

    public static function getModelClass(): string
    {
        return static::$modelClass;
    }

    public static function getModelAlias(): string
    {
        return static::$modelAlias;
    }

    public static function getFields(): array
    {
        return static::$fields;
    }

    public static function getField(string $fieldName): ?array
    {
        return static::$fields[$fieldName] ?? null;
    }

    public static function updateField(string $fieldName, array $config): void
    {
        if (isset(static::$fields[$fieldName])) {
            static::$fields[$fieldName] = array_merge(
                static::$fields[$fieldName],
                $config
            );
        }
    }

    public static function getRelationships(): array
    {
        return static::$relationships;
    }

    public static function getValidationRules(): array
    {
        $rules = [];

        foreach (static::$fields as $fieldName => $fieldConfig) {
            $fieldRules = [];

            if ($fieldConfig['validations']['is_required']) {
                $fieldRules[] = 'required';
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

            if (!empty($fieldRules)) {
                $rules[$fieldName] = $fieldRules;
            }
        }

        return $rules;
    }

    public static function boot(): void
    {
        ModelRegistry::register(static::class);
    }
}

// Автоматическая регистрация ресурса
{{className}}::boot();
STUB;
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
