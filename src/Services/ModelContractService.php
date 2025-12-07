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

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function getModelConfig(string $alias)
    {
        $models = Config::get('model-contracting.models', []);

        if (!isset($models[$alias])) {
            throw new \Exception("Модель с псевдонимом '{$alias}' не найдена в конфигурации");
        }

        return $models[$alias];
    }

    public function resolveModel(string $alias)
    {
        $config = $this->getModelConfig($alias);

        if (!class_exists($config['model'])) {
            throw new \Exception("Класс модели '{$config['model']}' не найден");
        }

        return app($config['model']);
    }

    public function validateFieldValue($fieldConfig, $value)
    {
        $type = $fieldConfig['type'];
        $fieldName = $fieldConfig['name'] ?? $fieldConfig['field_name'] ?? 'неизвестное поле';

        if (($fieldConfig['is_required'] ?? false) && $value === null) {
            throw new \Exception("Поле '{$fieldName}' обязательно для заполнения");
        }

        switch ($type) {
            case 'string':
                if (!is_string($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть строкой");
                }
                break;

            case 'integer':
                if (!is_int($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть целым числом");
                }
                break;

            case 'float':
                if ((!is_float($value) && !is_int($value)) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть числом с плавающей точкой");
                }
                break;

            case 'boolean':
                if (!is_bool($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть булевым значением");
                }
                break;

            case 'text':
                if (!is_string($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть текстом");
                }
                break;

            case 'string[]':
                if (!is_array($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть массивом");
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (!is_string($item)) {
                            throw new \Exception("Все элементы в поле '{$fieldName}' должны быть строками");
                        }
                    }
                }
                break;

            case 'integer[]':
                if (!is_array($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть массивом");
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (!is_int($item)) {
                            throw new \Exception("Все элементы в поле '{$fieldName}' должны быть целыми числами");
                        }
                    }
                }
                break;

            case 'float[]':
                if (!is_array($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть массивом");
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (!is_float($item) && !is_int($item)) {
                            throw new \Exception("Все элементы в поле '{$fieldName}' должны быть числами");
                        }
                    }
                }
                break;

            case 'boolean[]':
                if (!is_array($value) && $value !== null) {
                    throw new \Exception("Поле '{$fieldName}' должно быть массивом");
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (!is_bool($item)) {
                            throw new \Exception("Все элементы в поле '{$fieldName}' должны быть булевыми значениями");
                        }
                    }
                }
                break;
        }

        return true;
    }
}
