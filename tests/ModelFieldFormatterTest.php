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

    public function testDateTimeDisplayTextEqualsFormattedValue(): void
    {
        $fieldConfig = [
            'name' => 'created_at',
            'title' => 'Дата создания',
            'type' => 'date',
        ];

        $raw = '2026-05-19T12:23:04.000000Z';
        $value = $this->formatter->formatValue($raw, $fieldConfig);

        self::assertSame('19.05.2026 12:23', $value);
        self::assertSame('19.05.2026 12:23', $this->formatter->formatDisplayText($raw, $value, $fieldConfig, 'created_at'));
    }

    public function testBooleanDisplayTextIsDaNet(): void
    {
        $fieldConfig = [
            'name' => 'is_active',
            'title' => 'Активность',
            'type' => 'boolean',
        ];

        $value = $this->formatter->formatValue(true, $fieldConfig);

        self::assertSame('Да', $this->formatter->formatDisplayText(true, $value, $fieldConfig, 'is_active'));
        self::assertSame('Нет', $this->formatter->formatDisplayText(false, false, $fieldConfig, 'is_active'));
    }

    public function testAliasDisplayTextEqualsValue(): void
    {
        $fieldConfig = [
            'name' => 'alias',
            'title' => 'Алиас',
            'type' => 'string',
        ];

        $value = $this->formatter->formatValue('AgrOsago', $fieldConfig);

        self::assertSame('AgrOsago', $this->formatter->formatDisplayText('AgrOsago', $value, $fieldConfig, 'alias'));
    }

    public function testIdDisplayTextEqualsValue(): void
    {
        $fieldConfig = [
            'name' => 'id',
            'title' => 'Идентификатор',
            'type' => 'string',
        ];

        $id = '01AGOPRD000000000000000000';
        $value = $this->formatter->formatValue($id, $fieldConfig);

        self::assertSame($id, $this->formatter->formatDisplayText($id, $value, $fieldConfig, 'id'));
    }

    public function testFkDisplayTextFromContext(): void
    {
        $fieldConfig = [
            'name' => 'parent_id',
            'title' => 'Родительская группа',
            'type' => 'string',
            'is_FK' => true,
            'FK' => [
                'model_alias' => 'dictionaries',
                'relation_type' => 'belongsto',
            ],
        ];

        $parentId = '01jqxhgns3jvhh5tctzfx8gx7w';
        $value = $this->formatter->formatValue($parentId, $fieldConfig);
        $context = [
            'fkDisplays' => [
                'parent_id' => [
                    $parentId => 'ОСАГО',
                ],
            ],
        ];

        self::assertSame($parentId, $value);
        self::assertSame('ОСАГО', $this->formatter->formatDisplayText($parentId, $value, $fieldConfig, 'parent_id', $context));
    }

    public function testExtensionsDisplayText(): void
    {
        $fieldConfig = [
            'name' => 'options',
            'title' => 'Расширенные параметры',
            'type' => 'extensions',
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
        ];

        $value = $this->formatter->formatValue($raw, $fieldConfig);

        self::assertSame(
            "Возраст страхуемого: 19\r\nВид спорта: альпинизм",
            $this->formatter->formatDisplayText($raw, $value, $fieldConfig, 'options')
        );
    }
}
