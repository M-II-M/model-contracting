<?php

namespace MIIM\ModelContracting\Services;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class ModelFieldFormatter
{
    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array{titles: array<string, string|null>, selectMaps: array<string, array<string, string>>, enumMaps: array<string, array<string, string>>}
     */
    public function prepareFieldsContext(array $fields): array
    {
        $titles = [];
        $selectMaps = [];
        $enumMaps = [];

        foreach ($fields as $fieldName => $fieldConfig) {
            $titles[$fieldName] = $this->fieldTitle($fieldConfig);

            $type = $fieldConfig['type'] ?? 'string';

            if ($type === 'select') {
                $map = [];
                foreach ($fieldConfig['options'] ?? [] as $option) {
                    if (! is_array($option) || ! array_key_exists('value', $option)) {
                        continue;
                    }
                    $name = $option['name'] ?? $option['label'] ?? null;
                    $map[(string) $option['value']] = is_string($name) && $name !== ''
                        ? $name
                        : (string) $option['value'];
                }
                if ($map !== []) {
                    $selectMaps[$fieldName] = $map;
                }
            }

            if ($type === 'enum') {
                $map = [];
                foreach ($fieldConfig['enum_list'] ?? [] as $item) {
                    if (! is_array($item) || ! array_key_exists('value', $item)) {
                        continue;
                    }
                    $text = $item['text'] ?? $item['name'] ?? $item['label'] ?? null;
                    $map[(string) $item['value']] = is_string($text) && $text !== ''
                        ? $text
                        : (string) $item['value'];
                }
                if ($map !== []) {
                    $enumMaps[$fieldName] = $map;
                }
            }
        }

        return [
            'titles' => $titles,
            'selectMaps' => $selectMaps,
            'enumMaps' => $enumMaps,
        ];
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     * @param  array{titles: array<string, string|null>, selectMaps: array<string, array<string, string>>, enumMaps: array<string, array<string, string>>}|null  $context
     * @return array{value: mixed, display_text: string|null}
     */
    public function formatField(mixed $rawValue, array $fieldConfig, ?string $fieldName = null, ?array $context = null): array
    {
        $value = $this->formatValue($rawValue, $fieldConfig);

        return [
            'value' => $value,
            'display_text' => $this->formatDisplayText($rawValue, $value, $fieldConfig, $fieldName, $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    public function formatValue(mixed $rawValue, array $fieldConfig): mixed
    {
        $type = $fieldConfig['type'] ?? 'string';

        if ($rawValue === null) {
            return null;
        }

        return match ($type) {
            'boolean' => (bool) $rawValue,
            'integer' => is_numeric($rawValue) ? (int) $rawValue : $rawValue,
            'float' => is_numeric($rawValue) ? (float) $rawValue : $rawValue,
            'date', 'datetime' => $this->formatDateTimeValue($rawValue, $type),
            'extensions', 'json' => $this->normalizeExtensionsValue($rawValue),
            'select', 'enum', 'model_element_select' => $rawValue,
            'string[]', 'integer[]', 'float[]', 'boolean[]' => is_array($rawValue) ? $rawValue : $rawValue,
            default => $rawValue,
        };
    }

    /**
     * display_text — человекочитаемое значение для ячейки таблицы (заголовки колонок — в meta.title).
     *
     * @param  array<string, mixed>  $fieldConfig
     * @param  array{titles?: array<string, string|null>, selectMaps?: array<string, array<string, string>>, enumMaps?: array<string, array<string, string>>, fkDisplays?: array<string, array<string, string>>}|null  $context
     */
    public function formatDisplayText(
        mixed $rawValue,
        mixed $formattedValue,
        array $fieldConfig,
        ?string $fieldName = null,
        ?array $context = null,
    ): ?string {
        if ($rawValue === null) {
            return null;
        }

        if (($fieldConfig['is_FK'] ?? false) && $fieldName !== null && $context !== null) {
            $fkText = $this->resolveFkDisplayText($rawValue, $fieldName, $context);
            if ($fkText !== null) {
                return $fkText;
            }
        }

        $type = $fieldConfig['type'] ?? 'string';

        return match ($type) {
            'extensions', 'json' => $this->formatExtensionsDisplayText($rawValue, $fieldConfig),
            'select' => $this->resolveSelectDisplayText($rawValue, $fieldConfig, $fieldName, $context)
                ?? $this->scalarDisplayText($formattedValue),
            'enum' => $this->resolveEnumDisplayText($rawValue, $fieldConfig, $fieldName, $context)
                ?? $this->scalarDisplayText($formattedValue),
            'boolean' => $this->formatBooleanDisplayText($rawValue),
            'date', 'datetime' => $this->scalarDisplayText($formattedValue),
            default => $fieldName === 'id'
                ? $this->scalarDisplayText($formattedValue)
                : $this->scalarDisplayText($formattedValue),
        };
    }

    /**
     * @param  array{fkDisplays?: array<string, array<string, string>>}  $context
     */
    private function resolveFkDisplayText(mixed $rawValue, string $fieldName, array $context): ?string
    {
        if (! is_scalar($rawValue)) {
            return null;
        }

        $id = (string) $rawValue;
        $map = $context['fkDisplays'][$fieldName] ?? null;

        return is_array($map) ? ($map[$id] ?? null) : null;
    }

    private function scalarDisplayText(mixed $formattedValue): ?string
    {
        if ($formattedValue === null) {
            return null;
        }

        if (is_bool($formattedValue)) {
            return $this->formatBooleanDisplayText($formattedValue);
        }

        if (is_scalar($formattedValue)) {
            return (string) $formattedValue;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    private function fieldTitle(array $fieldConfig): ?string
    {
        $title = $fieldConfig['title'] ?? $fieldConfig['name'] ?? null;

        return is_string($title) && $title !== '' ? $title : null;
    }

    private function formatDateTimeValue(mixed $rawValue, string $type): ?string
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($rawValue);
        } catch (InvalidFormatException) {
            return is_scalar($rawValue) ? (string) $rawValue : null;
        }

        if ($type === 'datetime') {
            return $parsed->format('d.m.Y H:i');
        }

        if ($parsed->format('H:i:s') !== '00:00:00') {
            return $parsed->format('d.m.Y H:i');
        }

        return $parsed->format('d.m.Y');
    }

    private function normalizeExtensionsValue(mixed $rawValue): mixed
    {
        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $rawValue;
        }

        return $rawValue;
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    private function formatExtensionsDisplayText(mixed $rawValue, array $fieldConfig): ?string
    {
        $value = $this->normalizeExtensionsValue($rawValue);

        if (! is_array($value)) {
            return null;
        }

        if ($this->isExtensionsList($value)) {
            return $this->formatExtensionsListDisplayText($value);
        }

        return null;
    }

    /**
     * @param  list<mixed>  $items
     */
    private function isExtensionsList(array $items): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            if (! array_key_exists('name', $item) || ! array_key_exists('value', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function formatExtensionsListDisplayText(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            $label = $item['label'] ?? $item['name'] ?? '';
            $label = is_string($label) ? trim($label) : (is_scalar($label) ? (string) $label : '');

            $formattedValue = $this->formatExtensionItemValue($item);
            if ($formattedValue === null) {
                continue;
            }

            $lines[] = $label !== '' ? "{$label}: {$formattedValue}" : $formattedValue;
        }

        return implode("\r\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatExtensionItemValue(array $item): ?string
    {
        if (! array_key_exists('value', $item)) {
            return null;
        }

        $itemType = $item['type'] ?? 'string';
        $value = $item['value'];

        if (in_array($itemType, ['number', 'integer', 'float'], true) && is_numeric($value)) {
            return (string) $value;
        }

        if (in_array($itemType, ['boolean', 'bool'], true)) {
            return $this->formatBooleanDisplayText($value);
        }

        if (in_array($itemType, ['date', 'datetime'], true) && is_scalar($value) && $value !== '') {
            return $this->formatDateTimeValue($value, $itemType === 'datetime' ? 'datetime' : 'date') ?? (string) $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $this->formatBooleanDisplayText($value);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     * @param  array{titles: array<string, string|null>, selectMaps: array<string, array<string, string>>, enumMaps: array<string, array<string, string>>}|null  $context
     */
    private function resolveSelectDisplayText(
        mixed $rawValue,
        array $fieldConfig,
        ?string $fieldName,
        ?array $context,
    ): ?string {
        $needle = is_scalar($rawValue) ? (string) $rawValue : null;
        if ($needle === null) {
            return null;
        }

        if ($fieldName !== null && $context !== null) {
            $mapped = $context['selectMaps'][$fieldName][$needle] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $this->formatSelectDisplayText($rawValue, $fieldConfig);
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    private function formatSelectDisplayText(mixed $rawValue, array $fieldConfig): ?string
    {
        $options = $fieldConfig['options'] ?? [];
        if (! is_array($options)) {
            return null;
        }

        $needle = is_scalar($rawValue) ? (string) $rawValue : null;
        if ($needle === null) {
            return null;
        }

        foreach ($options as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option)) {
                continue;
            }

            if ((string) $option['value'] === $needle) {
                $name = $option['name'] ?? $option['label'] ?? null;

                return is_string($name) && $name !== '' ? $name : $needle;
            }
        }

        return is_scalar($rawValue) ? (string) $rawValue : null;
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     * @param  array{titles: array<string, string|null>, selectMaps: array<string, array<string, string>>, enumMaps: array<string, array<string, string>>}|null  $context
     */
    private function resolveEnumDisplayText(
        mixed $rawValue,
        array $fieldConfig,
        ?string $fieldName,
        ?array $context,
    ): ?string {
        $needle = is_scalar($rawValue) ? (string) $rawValue : null;
        if ($needle === null) {
            return null;
        }

        if ($fieldName !== null && $context !== null) {
            $mapped = $context['enumMaps'][$fieldName][$needle] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $this->formatEnumDisplayText($rawValue, $fieldConfig);
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    private function formatEnumDisplayText(mixed $rawValue, array $fieldConfig): ?string
    {
        $enumList = $fieldConfig['enum_list'] ?? [];
        if (! is_array($enumList)) {
            return null;
        }

        $needle = is_scalar($rawValue) ? (string) $rawValue : null;
        if ($needle === null) {
            return null;
        }

        foreach ($enumList as $item) {
            if (! is_array($item) || ! array_key_exists('value', $item)) {
                continue;
            }

            if ((string) $item['value'] === $needle) {
                $text = $item['text'] ?? $item['name'] ?? $item['label'] ?? null;

                return is_string($text) && $text !== '' ? $text : $needle;
            }
        }

        return is_scalar($rawValue) ? (string) $rawValue : null;
    }

    private function formatBooleanDisplayText(mixed $rawValue): string
    {
        return filter_var($rawValue, FILTER_VALIDATE_BOOLEAN) ? 'Да' : 'Нет';
    }
}
