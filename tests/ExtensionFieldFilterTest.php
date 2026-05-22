<?php

namespace MIIM\ModelContracting\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use MIIM\ModelContracting\Services\ExtensionFieldFilter;
use MIIM\ModelContracting\Services\ModelFilterOperator;
use PHPUnit\Framework\TestCase;

final class ExtensionFieldFilterTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$booted) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::schema()->create('dict_elements_filter_test', function ($table): void {
                $table->string('id')->primary();
                $table->json('options')->nullable();
            });

            Capsule::table('dict_elements_filter_test')->insert([
                [
                    'id' => 'russia',
                    'options' => json_encode([
                        ['name' => 'name', 'type' => 'string', 'label' => 'Название', 'value' => 'РОССИЯ'],
                        ['name' => 'ais_id', 'type' => 'string', 'label' => 'ID АИС', 'value' => '2570'],
                    ], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'id' => 'other',
                    'options' => json_encode([
                        ['name' => 'name', 'type' => 'string', 'label' => 'Название', 'value' => 'БЕЛАРУСЬ'],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]);

            self::$booted = true;
        }
    }

    public function testFilterByAttributeName(): void
    {
        $ids = $this->filteredIds('name', ModelFilterOperator::EQ, 'РОССИЯ');

        self::assertSame(['russia'], $ids);
    }

    public function testFilterByValueKeyMatchesAnyExtensionValue(): void
    {
        $ids = $this->filteredIds('value', ModelFilterOperator::EQ, 'РОССИЯ');

        self::assertSame(['russia'], $ids);
    }

    public function testFilterByValueKeyMatchesNumericValueInAnyAttribute(): void
    {
        $ids = $this->filteredIds('value', ModelFilterOperator::EQ, '2570');

        self::assertSame(['russia'], $ids);
    }

    /**
     * @return list<string>
     */
    private function filteredIds(string $attributeKey, string $operator, mixed $value): array
    {
        $query = $this->makeModel()->newQuery();
        (new ExtensionFieldFilter())->apply($query, 'options', $attributeKey, $operator, $value);

        return $query->orderBy('id')->pluck('id')->all();
    }

    private function makeModel(): Model
    {
        return new class extends Model
        {
            protected $table = 'dict_elements_filter_test';

            public $incrementing = false;

            protected $keyType = 'string';
        };
    }
}
