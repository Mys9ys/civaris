<?php

declare(strict_types=1);

return [
    'db' => [
        // OSPanel: MariaDB-11.4 слушает 127.0.1.16 (не 127.0.0.1!)
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
        'start_population' => 250,
        'start_poleis' => 10,
        'people_per_polis' => 25,
    ],
];