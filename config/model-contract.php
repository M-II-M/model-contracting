<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Конфигурация моделей
    |--------------------------------------------------------------------------
    |
    | Поддерживаемые типы данных:
    | - string: строка
    | - integer: целое число
    | - float: число с плавающей точкой
    | - boolean: булево значение
    | - text: текст
    | - string[]: массив строк
    | - integer[]: массив целых чисел
    | - float[]: массив чисел с плавающей точкой
    | - boolean[]: массив булевых значений
    |
    */
    'models' => [
//        'contracts' => [
//            'model' => App\Models\Contract::class,
//            'is_deletable' => false,
//            'is_editable' => true,
//            'fields' => [
//                'id' => [
//                    'type' => 'integer',
//                    'is_required' => true,
//                    'is_editable' => false,
//                    'is_sortable' => false,
//                    'is_filtered' => false,
//                ],
//                'number' => [
//                    'type' => 'string',
//                    'is_required' => true,
//                    'is_editable' => false,
//                    'is_sortable' => true,
//                    'is_filtered' => true,
//                ],
//                'client_id' => [
//                    'type' => 'integer',
//                    'is_required' => true,
//                    'is_editable' => true,
//                    'is_FK' => true,
//                    'FK' => [
//                        'model_alias' => 'clients',
//                        'relation_type' => 'belongsTo'
//                    ]
//                ],
//                'contract_number' => [
//                    'type' => 'string',
//                    'is_required' => true,
//                    'default_value' => null,
//                ],
//                'product_id' => [
//                    'type' => 'integer',
//                    'is_required' => true,
//                    'is_FK' => true,
//                    'FK' => [
//                        'model_alias' => 'products',
//                        'relation_type' => 'belongsTo'
//                    ]
//                ],
//                'has_promo' => [
//                    'type' => 'boolean',
//                    'is_required' => false,
//                    'default_value' => false,
//                    'is_editable' => true,
//                ],
//                'description' => [
//                    'type' => 'text',
//                    'is_required' => false,
//                    'is_editable' => true,
//                ],
//                'tags' => [
//                    'type' => 'string[]',
//                    'is_required' => false,
//                    'default_value' => [],
//                ],
//            ],
//        ],
        // Добавьте другие модели здесь
    ],

    /*
    |--------------------------------------------------------------------------
    | Конфигурация маршрутов
    |--------------------------------------------------------------------------
    |
    */
    'route_prefix' => 'api',
    'route_middleware' => ['api', 'model-contracting'],

    /*
    |--------------------------------------------------------------------------
    | Значения по умолчанию
    |--------------------------------------------------------------------------
    |
    */
    'defaults' => [
        'field' => [
            'is_editable' => true,
            'is_sortable' => true,
            'is_filtered' => true,
            'is_required' => false,
            'type' => 'string',
        ],
    ],
];
