<?php

namespace MIIM\ModelContracting\Tests;

use MIIM\ModelContracting\Services\ModelFilterOperator;
use MIIM\ModelContracting\Services\ModelFilterParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ModelFilterParserTest extends TestCase
{
    private ModelFilterParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ModelFilterParser();
    }

    public function testLegacyFilterParsedAsEq(): void
    {
        $conditions = $this->parser->parse([
            'status' => 'active',
            'parent_id' => '01abc',
        ]);

        self::assertSame([
            ['field' => 'status', 'extension_key' => null, 'operator' => ModelFilterOperator::EQ, 'value' => 'active'],
            ['field' => 'parent_id', 'extension_key' => null, 'operator' => ModelFilterOperator::EQ, 'value' => '01abc'],
        ], $conditions);
    }

    public function testOperatorNotation(): void
    {
        $conditions = $this->parser->parse([
            'title' => ['contains' => 'Осаго'],
            'price' => ['gt' => '100'],
            'date' => ['not_between' => '2025-12-27,2026-06-14'],
        ]);

        self::assertCount(3, $conditions);
        self::assertSame('contains', $conditions[0]['operator']);
        self::assertSame('gt', $conditions[1]['operator']);
        self::assertSame('not_between', $conditions[2]['operator']);
    }

    public function testSkipsGroupKeys(): void
    {
        $conditions = $this->parser->parse([
            '_AND' => [
                0 => ['status' => 'active'],
            ],
            'alias' => 'test',
        ]);

        self::assertCount(1, $conditions);
        self::assertSame('alias', $conditions[0]['field']);
    }

    public function testValidateRejectsUnknownOperatorForType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $conditions = $this->parser->parse([
            'is_active' => ['contains' => 'yes'],
        ]);

        $this->parser->validate($conditions, [
            'is_active' => ['type' => 'boolean', 'is_filtered' => true],
        ]);
    }

    public function testExtensionsAttributeFilterParsed(): void
    {
        $conditions = $this->parser->parse([
            'options.name' => ['eq' => 'РОССИЯ'],
        ]);

        self::assertSame([
            [
                'field' => 'options',
                'extension_key' => 'name',
                'operator' => ModelFilterOperator::EQ,
                'value' => 'РОССИЯ',
            ],
        ], $conditions);
    }

    public function testExtensionsFieldWithoutAttributeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('filter[options.name]');

        $conditions = $this->parser->parse([
            'options' => ['eq' => 'РОССИЯ'],
        ]);

        $this->parser->validate($conditions, [
            'options' => ['type' => 'extensions', 'is_filtered' => true],
        ]);
    }

    public function testNonExtensionsFieldWithDotRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $conditions = $this->parser->parse([
            'alias.code' => ['eq' => 'x'],
        ]);

        $this->parser->validate($conditions, [
            'alias' => ['type' => 'string', 'is_filtered' => true],
        ]);
    }
}
