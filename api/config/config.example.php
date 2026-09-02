<?php

declare(strict_types=1);

return [
    'db' => [
        // OSPanel: MariaDB слушает 127.0.1.16 (не 127.0.0.1!)
        'host' => '127.0.1.16',
        'port' => 3306,
        'name' => 'civaris',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Civaris',
        'debug' => true,
        'cors_origin' => '*',
        'free_population_cap' => 500,
        // Срез A: малая карта 5×20
        'default_map_size' => 'small',
        'start_villages' => 5,
        'people_per_village' => 20,
        'start_stock_days' => 7,
        'start_stock_buffer' => 1.2,
        // 3 сборщика × 2 культуры → 10 жителей/культура + 25% на склад
        'gatherers_per_crop' => 3,
        'residents_per_crop' => 10,
        'gather_crop_surplus_ratio' => 1.25,
        'gather_water_surplus_ratio' => 1.25,
        // Календарь: 4 дня × 3 недели = 12 дней/мес, 4 мес = 48 дней/год
        'days_per_week' => 4,
        'weeks_per_month' => 3,
        'months_per_year' => 4,
        'tick_energy_penalty_partial' => 25,
        'tick_energy_penalty_hungry' => 40,
    ],
];
