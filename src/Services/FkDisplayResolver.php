<?php

namespace MIIM\ModelContracting\Services;

use Illuminate\Database\Eloquent\Model;

class FkDisplayResolver
{
    public function __construct(
        private ModelRegistryService $registryService,
    ) {}

    /**
     * @param  iterable<int, Model>  $models
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, string>>
     */
    public function resolveBatch(iterable $models, array $fields): array
    {
        $maps = [];

        foreach ($fields as $fieldName => $fieldConfig) {
            if (! ($fieldConfig['is_FK'] ?? false)) {
                continue;
            }

            $fkConfig = $fieldConfig['FK'] ?? null;
            if (! is_array($fkConfig) || empty($fkConfig['model_alias'])) {
                continue;
            }

            $ids = [];
            foreach ($models as $model) {
                $rawId = $model->getAttribute($fieldName);
                if ($rawId !== null && $rawId !== '') {
                    $ids[(string) $rawId] = true;
                }
            }

            if ($ids === []) {
                continue;
            }

            $modelClass = $this->registryService->getModelClassByAlias($fkConfig['model_alias']);
            if (! $modelClass) {
                continue;
            }

            $fieldMap = [];
            foreach ($modelClass::query()->whereIn('id', array_keys($ids))->get() as $related) {
                $fieldMap[(string) $related->getAttribute('id')] = $this->resolveRelatedDisplayText($related, $fkConfig);
            }

            $maps[$fieldName] = $fieldMap;
        }

        return $maps;
    }

    /**
     * @param  array<string, mixed>  $fkConfig
     */
    public function resolveRelatedDisplayText(Model $related, array $fkConfig): string
    {
        $displayField = $fkConfig['display_field'] ?? null;
        if (is_string($displayField) && $displayField !== '') {
            $attribute = $related->getAttribute($displayField);
            if (is_scalar($attribute) && (string) $attribute !== '') {
                return (string) $attribute;
            }
        }

        $extensions = $related->getAttribute('options');
        if ($extensions === null) {
            $extensions = $related->getAttribute('extensions');
        }

        if (is_array($extensions)) {
            $fromExtensions = $this->extractNameFromExtensions($extensions);
            if ($fromExtensions !== null) {
                return $fromExtensions;
            }
        }

        $alias = $related->getAttribute('alias');
        if (is_string($alias) && $alias !== '') {
            return $alias;
        }

        return (string) $related->getAttribute('id');
    }

    /**
     * @param  list<mixed>  $extensions
     */
    public function extractNameFromExtensions(array $extensions): ?string
    {
        foreach ($extensions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $nameKey = $item['name'] ?? null;
            if ($nameKey !== 'name' && $nameKey !== 'title' && $nameKey !== 'label') {
                continue;
            }

            $value = $item['value'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        foreach ($extensions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = $item['label'] ?? $item['name'] ?? null;
            $value = $item['value'] ?? null;
            if (is_string($label) && trim($label) !== '' && is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
