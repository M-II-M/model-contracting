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

    protected $description = 'Generate contracting resource for a model';

    private string $defaultNamespace = 'App\\Contracting';

    public function handle()
    {
        $modelClass = $this->argument('model');
        $alias = $this->option('alias') ?: Str::snake(Str::plural(class_basename($modelClass)));

        try {
            $model = app($modelClass);
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);

            $this->info("Generating resource for model: {$modelClass}");
            $this->info("Table: {$table}");
            $this->info("Alias: {$alias}");

            $fields = $this->analyzeModelFields($modelClass, $table, $columns);

            $this->generateResourceClass($modelClass, $alias, $fields);

            $this->info("Resource generation completed successfully!");
            $this->info("Resource class: " . $this->getResourceClassName($modelClass));

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    /**
     * Анализ полей модели
     * @param string $modelClass класс модели
     * @param string $table табилца модели
     * @param array $columns столбцы
     * @return array
     */
    private function analyzeModelFields(string $modelClass, string $table, array $columns): array
    {
        $fields = [];

        foreach ($columns as $column) {
            try {
                $columnType = DB::getSchemaBuilder()->getColumnType($table, $column);
                $defaultValue = $this->getDefaultValue($modelClass, $table, $column);
                $isFK = Str::endsWith($column, '_id');

                // Определяем связанную модель для FK
                $fkInfo = null;
                if ($isFK) {
                    $relationInfo = $this->guessRelationInfo($modelClass, $column);

                    if ($relationInfo) {
                        $fkInfo = [
                            'model_alias' => Str::snake(Str::plural(class_basename($relationInfo['model']))),
                            'relation_type' => $relationInfo['type'],
                        ];
                    }
                }

                // Определяем настройки по умолчанию
                $isEditable = $column !== 'id'; // id не редактируем по умолчанию
                $isSortable = $column !== 'id'; // id не сортируем по умолчанию

                $fields[$column] = [
                    'name' => $column,
                    'type' => $this->mapColumnType($columnType),
                    'default_value' => $defaultValue,
                    'validations' => [
                        'is_required' => $this->isColumnRequired($table, $column),
                    ],
                    'is_selected_dict' => false,
                    'selected_dict_id' => null,
                    'is_editable' => $isEditable,
                    'is_sortable' => $isSortable,
                    'is_filtered' => true,
                    'is_FK' => $isFK,
                    'FK' => $fkInfo,
                ];
            } catch (\Exception $e) {
                $this->warn("Error analyzing column {$column}: " . $e->getMessage());
                continue;
            }
        }

        return $fields;
    }

    /**
     * Определение связанной модели и типа связи
     * @param string $modelClass класс модели
     * @param string $column столбец
     * @return array|null ['model' => string, 'type' => string] или null
     * @throws \ReflectionException
     */
    private function guessRelationInfo(string $modelClass, string $column): ?array
    {
        // Анализ класса модели через ReflectionClass
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getMethods() as $method) {
            //Отбираем только публичный и не статичные методы
            if ($method->isPublic() && !$method->isStatic()) {
                try {
                    // Попытка вызвать метод
                    $modelInstance = app($modelClass);
                    $returnValue = $method->invoke($modelInstance);

                    // Проверка является ли результат вызова метода связью
                    //is_object($returnValue) - является ли объектом
                    // getForeignKeyName - имя FK
                    // getRelated - связанная модель
                    if (is_object($returnValue) &&
                        method_exists($returnValue, 'getForeignKeyName') &&
                        method_exists($returnValue, 'getRelated')) {

                        // Получение имени FK и столбца
                        if ($returnValue->getForeignKeyName() === $column) {
                            return [
                                'model' => get_class($returnValue->getRelated()),
                                'type' => strtolower(class_basename(get_class($returnValue))),
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        // Попытка определить только модель по имени столбца при fallback
        $relatedModelName = Str::studly(Str::replaceLast('_id', '', $column));
        $possibleNamespaces = ['App\\Models\\', 'App\\'];

        foreach ($possibleNamespaces as $namespace) {
            $className = $namespace . $relatedModelName;
            if (class_exists($className)) {
                return [
                    'model' => $className,
                    'type' => 'belongsTo', // Тип по умолчанию для fallback
                ];
            }
        }

        return null;
    }

    /**
     * Маппинг типов столбцов
     * @param string $columnType тип столбца
     * @return string
     */
    private function mapColumnType(string $columnType): string
    {
        $mapping = [
            'string' => ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'],
            'integer' => ['int', 'integer', 'bigint', 'mediumint', 'smallint', 'tinyint'],
            'float' => ['float', 'double', 'decimal', 'numeric', 'real'],
            'boolean' => ['boolean', 'bool', 'tinyint(1)'],
            'date' => ['date', 'datetime', 'timestamp', 'timestamptz'],
            'json' => ['json', 'jsonb'],
        ];

        foreach ($mapping as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (Str::contains($columnType, $pattern)) {
                    return $type;
                }
            }
        }

        return 'string';
    }

    /**
     * Получение значений столбцов по умолчанию
     * @param string $modelClass класс модели
     * @param string $column столбцы модели
     * @return mixed
     */
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

    /**
     * Определение является ли столбец обязательным
     * @param string $table таблица модели
     * @param string $column столбцы таблицы модели
     * @return bool
     */
    private function isColumnRequired(string $table, string $column): bool
    {
        try {
            $columns = Schema::getColumns($table);

            foreach ($columns as $col) {
                if ($col['name'] === $column) {
                    return !$col['nullable'];
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->warn("Error checking column requirement: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Генерация класса ресурса контрактинга модели на основе стаба
     * @param string $modelClass класс модели
     * @param string $alias алиас модели
     * @param array $fields поля модели
     */
    private function generateResourceClass(string $modelClass, string $alias, array $fields): void
    {
        $className = class_basename($modelClass) . 'ContractingResource';

        $path = app_path('Contracting');
        $namespace = $this->defaultNamespace;

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $stubPath = __DIR__ . '/../Stubs/ModelResource.stub';
        $stub = file_get_contents($stubPath);

        $fieldsString = $this->formatArrayForPhp($fields, 2);

        $replacements = [
            '{{namespace}}' => $namespace,
            '{{className}}' => $className,
            '{{modelClass}}' => $modelClass,
            '{{modelAlias}}' => $alias,
            '{{fields}}' => $fieldsString,
        ];

        $content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $stub
        );

        $filePath = $path . '/' . $className . '.php';
        file_put_contents($filePath, $content);

        $this->info("Resource file created: {$filePath}");
    }

    /**
     * Преобразование массива в отформатированныйц PHP код
     * @param array $array массив для преобразования
     * @param int $indentLevel колличество отступов
     * @return string
     */
    private function formatArrayForPhp(array $array, int $indentLevel = 0): string
    {
        $indent = str_repeat('    ', $indentLevel);
        $lines = [];

        foreach ($array as $key => $value) {
            $keyString = is_string($key) ? "'{$key}'" : $key;

            if (is_array($value)) {
                $formattedValue = $this->formatArrayForPhp($value, $indentLevel + 1);
                $lines[] = "{$keyString} => {$formattedValue},";
            } elseif (is_string($value)) {
                $lines[] = "{$keyString} => '{$value}',";
            } elseif (is_bool($value)) {
                $lines[] = "{$keyString} => " . ($value ? 'true' : 'false') . ",";
            } elseif (is_null($value)) {
                $lines[] = "{$keyString} => null,";
            } else {
                $lines[] = "{$keyString} => {$value},";
            }
        }

        if (empty($lines)) {
            return '[]';
        }

        return "[\n" . $indent . '    ' . implode("\n" . $indent . '    ', $lines) . "\n" . $indent . "]";
    }

    /**
     * Формирование имени класса ресурса
     * @param string $modelClass класс модели
     * @return string
     */
    private function getResourceClassName(string $modelClass): string
    {
        return $this->defaultNamespace . '\\' . class_basename($modelClass) . 'ContractingResource';
    }
}
