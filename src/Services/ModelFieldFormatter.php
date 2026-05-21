<?php

namespace MIIM\ModelContracting\Services;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class ModelFieldFormatter
{
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
            'json' => $this->normalizeJsonValue($rawValue),
            'select', 'enum', 'model_element_select' => $rawValue,
            'string[]', 'integer[]', 'float[]', 'boolean[]' => is_array($rawValue) ? $rawValue : $rawValue,
            default => $rawValue,
        };
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    public function formatDisplayText(mixed $rawValue, array $fieldConfig): ?string
    {
        $type = $fieldConfig['type'] ?? 'string';

        if ($rawValue === null) {
            return $this->fieldTitle($fieldConfig);
        }

        return match ($type) {
            'json' => $this->formatJsonDisplayText($rawValue, $fieldConfig),
            'select' => $this->formatSelectDisplayText($rawValue, $fieldConfig)
                ?? $this->fieldTitle($fieldConfig),
            'enum' => $this->formatEnumDisplayText($rawValue, $fieldConfig)
                ?? $this->fieldTitle($fieldConfig),
            'boolean' => $this->fieldTitle($fieldConfig),
            default => $this->fieldTitle($fieldConfig),
        };
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

    private function normalizeJsonValue(mixed $rawValue): mixed
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
    private function formatJsonDisplayText(mixed $rawValue, array $fieldConfig): ?string
    {
        $value = $this->normalizeJsonValue($rawValue);

        if (! is_array($value)) {
            return $this->fieldTitle($fieldConfig);
        }

        if ($this->isStructuredOptionsList($value)) {
            return $this->formatStructuredOptionsDisplayText($value);
        }

        return $this->fieldTitle($fieldConfig);
    }

    /**
     * @param  list<mixed>  $items
     */
    private function isStructuredOptionsList(array $items): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            if (! array_key_exists('value', $item)) {
                return false;
            }

            if (! array_key_exists('label', $item) && ! array_key_exists('name', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function formatStructuredOptionsDisplayText(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            $label = $item['label'] ?? $item['name'] ?? '';
            $label = is_string($label) ? trim($label) : (is_scalar($label) ? (string) $label : '');

            $formattedValue = $this->formatStructuredOptionItemValue($item);
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
    private function formatStructuredOptionItemValue(array $item): ?string
    {
        if (! array_key_exists('value', $item)) {
            return null;
        }

        $itemType = $item['type'] ?? 'string';
        $value = $item['value'];

        if ($itemType === 'boolean') {
            return $this->formatBooleanDisplayText($value);
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
