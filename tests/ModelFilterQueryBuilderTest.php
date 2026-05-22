<?php

namespace MIIM\ModelContracting\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use MIIM\ModelContracting\Services\ModelFilterOperator;
use MIIM\ModelContracting\Services\ModelFilterQueryBuilder;
use PHPUnit\Framework\TestCase;

final class ModelFilterQueryBuilderTest extends TestCase
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

            Capsule::schema()->create('items', function ($table): void {
                $table->string('id')->primary();
                $table->string('title')->nullable();
                $table->boolean('is_active')->default(true);
            });

            self::$booted = true;
        }
    }

    public function testContainsUsesLowerLikeOnSqlite(): void
    {
        $model = $this->makeItemModel();
        $query = $model->newQuery();
        $builder = new ModelFilterQueryBuilder();

        $builder->apply($query, [
            [
                'field' => 'title',
                'operator' => ModelFilterOperator::CONTAINS,
                'value' => 'Осаго',
            ],
        ], [
            'title' => ['type' => 'string'],
        ]);

        $sql = strtolower($query->toSql());
        self::assertStringContainsString('like', $sql);
        self::assertStringContainsString('lower', $sql);
    }

    private function makeItemModel(): Model
    {
        return new class extends Model
        {
            protected $table = 'items';

            public $incrementing = false;

            protected $keyType = 'string';
        };
    }

    public function testIsNullOperator(): void
    {
        $model = $this->makeItemModel();
        $query = $model->newQuery();
        $builder = new ModelFilterQueryBuilder();

        $builder->apply($query, [
            [
                'field' => 'title',
                'operator' => ModelFilterOperator::IS_NULL,
                'value' => 'true',
            ],
        ], [
            'title' => ['type' => 'string'],
        ]);

        self::assertStringContainsString('"title" is null', $query->toSql());
    }
}
