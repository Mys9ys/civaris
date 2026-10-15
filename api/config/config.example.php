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
        // Только локалка/тесты: при каждом запросе без сессии — войти как admin.
        'dev_auto_admin' => false,
        'cors_origin' => '*',
        'free_population_cap' => 500,
        // Тест: 1 деревня × 30 чел. (канон 2026-09-09)
        'default_map_size' => 'small',
        'start_villages' => 1,
        'people_per_village' => 30,
        /** 4 общежития ×10; старт 30/40, одно пустое под +5. */
        'housing_capacity' => 40,
        'start_stock_days' => 7,
        'start_stock_buffer' => 1.2,
        // ЯблПром: 2 сборщика; урожай на всю деревню + surplus
        'gatherers_per_crop' => 2,
        'gather_crop_surplus_ratio' => 1.25,
        'gather_water_surplus_ratio' => 1.25,
        // Деньги (ещё не в тике)
        'start_benefit' => 100,
        'wage_month' => 100,
        'vat_rate' => 0.05,
        'shop_margin_rate' => 0.07,
        'company_start_credit' => 50,
        'company_license_fee' => 4,
        'company_grossbook_fee' => 4,
        'bank_account_fee_month' => 2,
        'bank_credit_rate_year' => 0.15,
        /** Эмиссия в казну на каждого живого: старт = N×200; при +человеке — ещё +200 (без оплаты). */
        'money_issue_per_person' => 200,
        /** Устарело: если money_issue_per_person > 0, казна = N×issue, иначе этот фикс. */
        'start_treasury_balance' => 10000,
        'bank_start_capital' => 1500,
        // Календарь: пн·ср·пт·вс (4-й день — выходной)
        'days_per_week' => 4,
        'weeks_per_month' => 3,
        'months_per_year' => 4,
        'weekday_names' => ['понедельник', 'среда', 'пятница', 'воскресенье'],
        'rest_day_in_week' => 4,
        // Условные сутки ~18 ч
        'day_hours_total' => 18,
        'day_hours_sleep_min' => 3,
        'day_hours_awake' => 12,
        'day_hours_work' => 6,
        'tick_energy_penalty_partial' => 25,
        'tick_energy_penalty_hungry' => 40,
    ],
];
