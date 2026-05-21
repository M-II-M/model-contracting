<?php

namespace MIIM\ModelContracting\Tests;

use MIIM\ModelContracting\Services\ModelFieldFormatter;
use PHPUnit\Framework\TestCase;

final class ModelFieldFormatterTest extends TestCase
{
    private ModelFieldFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ModelFieldFormatter();
    }

    public function testDateTimeValueAndTitleDisplayText(): void
    {
        $fieldConfig = [
            'name' => 'created_at',
            'title' => 'Дата создания',
            'type' => 'date',
        ];

        $raw = '2026-05-19T12:23:04.000000Z';

        self::assertSame('19.05.2026 12:23', $this->formatter->formatValue($raw, $fieldConfig));
        self::assertSame('Дата создания', $this->formatter->formatDisplayText($raw, $fieldConfig));
    }

    public function testJsonOptionsDisplayText(): void
    {
        $fieldConfig = [
            'name' => 'options',
            'title' => 'Опции',
            'type' => 'json',
        ];

        $raw = [
            [
                'name' => 'age',
                'type' => 'integer',
                'label' => 'Возраст страхуемого',
                'value' => 19,
            ],
            [
                'name' => 'sport',
                'type' => 'string',
                'label' => 'Вид спорта',
                'value' => 'альпинизм',
            ],
            [
                'name' => 'promo',
                'type' => 'string',
                'label' => 'Промокод',
                'value' => 'promocode',
            ],
        ];

        self::assertSame($raw, $this->formatter->formatValue($raw, $fieldConfig));
        self::assertSame(
            "Возраст страхуемого: 19\r\nВид спорта: альпинизм\r\nПромокод: promocode",
            $this->formatter->formatDisplayText($raw, $fieldConfig)
        );
    }

    public function testSelectDisplayTextUsesOptionName(): void
    {
        $fieldConfig = [
            'name' => 'relation_name',
            'title' => 'Тип связи',
            'type' => 'select',
            'options' => [
                ['name' => 'Категория ТС → Марка', 'value' => 'vehicle_category_mark'],
            ],
        ];

        self::assertSame('vehicle_category_mark', $this->formatter->formatValue('vehicle_category_mark', $fieldConfig));
        self::assertSame('Категория ТС → Марка', $this->formatter->formatDisplayText('vehicle_category_mark', $fieldConfig));
    }

    public function testScalarFieldDisplayTextUsesTitle(): void
    {
        $fieldConfig = [
            'name' => 'alias',
            'title' => 'Алиас',
            'type' => 'string',
        ];

        self::assertSame('osago_countries_elem_2570', $this->formatter->formatValue('osago_countries_elem_2570', $fieldConfig));
        self::assertSame('Алиас', $this->formatter->formatDisplayText('osago_countries_elem_2570', $fieldConfig));
    }

    public function testDictElementOptionsShape(): void
    {
        $fieldConfig = [
            'name' => 'options',
            'title' => 'Опции',
            'type' => 'json',
        ];

        $raw = [
            [
                'name' => 'name',
                'type' => 'string',
                'label' => 'Название',
                'value' => 'РОССИЯ',
            ],
            [
                'name' => 'is_unfriendly',
                'type' => 'boolean',
                'label' => 'Недружественная страна',
                'value' => false,
            ],
        ];

        self::assertSame(
            "Название: РОССИЯ\r\nНедружественная страна: Нет",
            $this->formatter->formatDisplayText($raw, $fieldConfig)
        );
    }
}
