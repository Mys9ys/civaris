<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

final class WorldService
{
    /** Тест: одна стартовая провинция. */
    private const SMALL_START_PROVINCES = [2];

    /** Один вид фруктов на деревню (торговля между деревнями позже). */
    private const VILLAGE_FRUITS = [
        2 => 'apple',
    ];

    private const PERSON_NAMES = [
        'Артём', 'Борис', 'Вера', 'Галина', 'Дарья', 'Егор', 'Жанна', 'Илья',
        'Кира', 'Лев', 'Мария', 'Никита', 'Ольга', 'Павел', 'Рита', 'Сергей',
        'Татьяна', 'Ульяна', 'Фёдор', 'Юлия', 'Анна', 'Виктор', 'Глеб', 'Диана',
        'Елена', 'Захар', 'Инна', 'Константин', 'Лариса', 'Михаил',
    ];

    /** @var array<string, string> */
    private const JOB_LABELS = [
        'gatherer' => 'Сборщик плодов',
        'chief' => 'Вождь',
        'banker' => 'Банкир',
        'shopkeeper' => 'Продавец',
        'water_carrier' => 'Сборщик воды',
        'clay_digger' => 'Добытчик глины',
        'woodcutter' => 'Лесоруб',
        'builder' => 'Строитель',
        'potter' => 'Гончар',
        'carrier' => 'Транспорт',
        'designer' => 'Проектировщик',
        'craft_fab' => 'Ремесленник',
        'wheat_farmer' => 'Пшеничник',
        'sheep_farmer' => 'Овцевод',
        'cow_farmer' => 'Скотник',
        'tailor' => 'Швея',
        'cotton_farmer' => 'Хлопковод',
        'baker' => 'Пекарь',
        'dairy_worker' => 'Молочник',
        'tavern' => 'Харчевник',
        'stone_digger' => 'Каменотёс',
        'chicken_farmer' => 'Птичник',
    ];

    /** job_key → код компании (канон старта). Вождь/казна — не фирма. */
    private const JOB_COMPANY = [
        'banker' => 'ЧБ',
        'gatherer' => 'ЯблПром',
        'water_carrier' => 'Водосбор',
        'clay_digger' => 'ГонПром',
        'potter' => 'ГонПром',
        'woodcutter' => 'ЛесПром',
        'builder' => 'СтройКом',
        'carrier' => 'ТРКом',
        'designer' => 'ПрБюро',
        'shopkeeper' => 'Магазин',
        'craft_fab' => 'РемеслФабр',
        'wheat_farmer' => 'ПшАгро',
        'sheep_farmer' => 'ОвФерма',
        'cow_farmer' => 'КорФерма',
        'tailor' => 'ШвейФаб',
        'cotton_farmer' => 'ХлопАгро',
        'baker' => 'Пекарня',
        'dairy_worker' => 'МолокоЗавод',
        'tavern' => 'Харчевня',
        'stone_digger' => 'КамДоб',
        'chicken_farmer' => 'КурФерм',
    ];

    /** Дни недели: 1 пн, 2 ср, 3 пт, 4 вс (выходной). */
    private const WEEKDAY_NAMES_DEFAULT = ['понедельник', 'среда', 'пятница', 'воскресенье'];

    /**
     * Компании щелчка (Sheet). kind: bank|firm. Казна — не компания.
     *
     * @var list<array{code: string, title: string, kind: string, workers: int}>
     */
    private const START_COMPANIES = [
        ['code' => 'ЧБ', 'title' => 'Частный банк', 'kind' => 'bank', 'workers' => 1],
        ['code' => 'ЯблПром', 'title' => 'Фруктовая компания', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'Водосбор', 'title' => 'Сбор и разлив воды', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'ГонПром', 'title' => 'Глина и гончарство', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'ЛесПром', 'title' => 'Рубка леса', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'СтройКом', 'title' => 'Строительная компания', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'ТРКом', 'title' => 'Транспортная компания', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'ПрБюро', 'title' => 'Проектное бюро', 'kind' => 'firm', 'workers' => 1],
        ['code' => 'Магазин', 'title' => 'Магазин-рынок', 'kind' => 'firm', 'workers' => 1],
        ['code' => 'РемеслФабр', 'title' => 'Ремесленная фабрика', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'ПшАгро', 'title' => 'Сбор пшеницы', 'kind' => 'firm', 'workers' => 1],
        ['code' => 'ОвФерма', 'title' => 'Овечья ферма', 'kind' => 'firm', 'workers' => 1],
        ['code' => 'КорФерма', 'title' => 'Коровья ферма', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'ШвейФаб', 'title' => 'Швейная фабрика', 'kind' => 'firm', 'workers' => 1],
        ['code' => 'ХлопАгро', 'title' => 'Хлопок и ткани', 'kind' => 'firm', 'workers' => 1],
        ['code' => 'Харчевня', 'title' => 'Харчевня', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'КамДоб', 'title' => 'Добыча камня', 'kind' => 'firm', 'workers' => 2],
        ['code' => 'КурФерм', 'title' => 'Птицеферма', 'kind' => 'firm', 'workers' => 1],
    ];

    public function __construct(
        private PDO $pdo,
        private array $appConfig
    ) {
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, population_cap, population, day, month, year, status,
                    map_size, map_seed, created_at
             FROM worlds WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getOwned(int $worldId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, population_cap, population, day, month, year, status,
                    map_size, map_seed
             FROM worlds WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$worldId, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->enrichWorldCalendar($row) : null;
    }

    /** @return array{days_per_week: int, weeks_per_month: int, days_per_month: int, months_per_year: int} */
    private function calendarLimits(): array
    {
        $daysPerWeek = max(1, (int) ($this->appConfig['days_per_week'] ?? 4));
        $weeksPerMonth = max(1, (int) ($this->appConfig['weeks_per_month'] ?? 3));
        $monthsPerYear = max(1, (int) ($this->appConfig['months_per_year'] ?? 4));

        return [
            'days_per_week' => $daysPerWeek,
            'weeks_per_month' => $weeksPerMonth,
            'days_per_month' => $daysPerWeek * $weeksPerMonth,
            'months_per_year' => $monthsPerYear,
        ];
    }

    /** @param array<string, mixed> $world */
    private function enrichWorldCalendar(array $world): array
    {
        $cal = $this->calendarLimits();
        $day = max(1, (int) $world['day']);
        $dayInWeek = (($day - 1) % $cal['days_per_week']) + 1;
        $weekdayNames = $this->appConfig['weekday_names'] ?? self::WEEKDAY_NAMES_DEFAULT;
        if (!is_array($weekdayNames) || $weekdayNames === []) {
            $weekdayNames = self::WEEKDAY_NAMES_DEFAULT;
        }
        $restDay = (int) ($this->appConfig['rest_day_in_week'] ?? 4);

        $world['week'] = intdiv($day - 1, $cal['days_per_week']) + 1;
        $world['day_in_week'] = $dayInWeek;
        $world['weekday_name'] = $weekdayNames[$dayInWeek - 1] ?? ('день ' . $dayInWeek);
        $world['is_rest_day'] = $dayInWeek === $restDay;
        $world['calendar'] = array_merge($cal, [
            'weekday_names' => array_values($weekdayNames),
            'rest_day_in_week' => $restDay,
            'day_hours_total' => (int) ($this->appConfig['day_hours_total'] ?? 18),
            'day_hours_sleep_min' => (int) ($this->appConfig['day_hours_sleep_min'] ?? 3),
            'day_hours_awake' => (int) ($this->appConfig['day_hours_awake'] ?? 12),
            'day_hours_work' => (int) ($this->appConfig['day_hours_work'] ?? 6),
        ]);
        return $world;
    }

    /** @return array{0: int, 1: int, 2: int} day, month, year */
    private function advanceCalendar(int $day, int $month, int $year): array
    {
        $cal = $this->calendarLimits();
        $day++;
        if ($day > $cal['days_per_month']) {
            $day = 1;
            $month++;
            if ($month > $cal['months_per_year']) {
                $month = 1;
                $year++;
            }
        }

        return [$day, $month, $year];
    }

    /**
     * Тест: 1 деревня × 30 человек, склады по нормам щелчка + запас.
     */
    public function create(int $userId, string $name, ?int $mapSeed = null): array
    {
        $cap = (int) $this->appConfig['free_population_cap'];
        $villages = (int) ($this->appConfig['start_villages'] ?? 1);
        $perVillage = (int) ($this->appConfig['people_per_village'] ?? 30);
        $startPop = $villages * $perVillage;
        $stockDays = (int) ($this->appConfig['start_stock_days'] ?? 7);
        $buffer = (float) ($this->appConfig['start_stock_buffer'] ?? 1.2);
        $mapSize = (string) ($this->appConfig['default_map_size'] ?? 'small');
        $seed = $mapSeed ?? random_int(1, 99999);

        if ($mapSize !== 'small' || $villages !== count(self::SMALL_START_PROVINCES)) {
            throw new \InvalidArgumentException(
                'Тестовый срез: малая карта, start_villages = число SMALL_START_PROVINCES'
            );
        }

        $needs = $this->loadClickNeeds();
        $kcalPerDay = $needs['kcal_day'];
        $waterPerDay = $needs['water_day'];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO worlds (user_id, name, population_cap, population, day, month, year, map_size, map_seed)
                 VALUES (?, ?, ?, ?, 1, 1, 1, ?, ?)'
            );
            $stmt->execute([$userId, $name, $cap, $startPop, $mapSize, $seed]);
            $worldId = (int) $this->pdo->lastInsertId();

            $insSettlement = $this->pdo->prepare(
                'INSERT INTO settlements
                   (world_id, name, kind, is_capital, pos_x, pos_y, population, province_id, housing_kind, housing_capacity)
                 VALUES (?, ?, \'village\', 1, ?, ?, ?, ?, \'dormitory\', ?)'
            );
            $insPerson = $this->pdo->prepare(
                'INSERT INTO people (world_id, settlement_id, name, energy, status, job_key, job_crop)
                 VALUES (?, ?, ?, 100, \'alive\', ?, ?)'
            );

            foreach (self::SMALL_START_PROVINCES as $i => $provinceId) {
                [$col, $row] = $this->provinceGridPos($provinceId);
                $housingCap = (int) ($this->appConfig['housing_capacity'] ?? 40);
                $insSettlement->execute([
                    $worldId,
                    'Макленд',
                    $col * 100,
                    $row * 100,
                    $perVillage,
                    $provinceId,
                    $housingCap,
                ]);
                $settlementId = (int) $this->pdo->lastInsertId();
                $jobSlots = $this->villageJobSlots($this->fruitPairForProvince($provinceId));

                for ($p = 0; $p < $perVillage; $p++) {
                    $slot = $jobSlots[$p] ?? ['job_key' => 'gatherer', 'job_crop' => null];
                    $insPerson->execute([
                        $worldId,
                        $settlementId,
                        self::PERSON_NAMES[$p % count(self::PERSON_NAMES)] . ' ' . ($p + 1),
                        $slot['job_key'],
                        $slot['job_crop'] ?? null,
                    ]);
                }

                $this->seedSettlementStock($settlementId, $perVillage, $kcalPerDay, $waterPerDay, $stockDays, $buffer);
                $this->seedVillageLayout($settlementId, $perVillage);
                $this->bootstrapClickEconomy($worldId, $settlementId);
            }

            $this->insertWorldCreatedChronicle($worldId, $villages, $perVillage, $startPop, $stockDays, $buffer, $cap, $seed);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getOwned($worldId, $userId) ?? [];
    }

    /** Админ: календарь 1.1.1, склады и энергия как при создании, летопись с нуля. */
    public function reset(int $worldId, int $userId): array
    {
        $world = $this->getOwned($worldId, $userId);
        if (!$world) {
            throw new \RuntimeException('Мир не найден', 404);
        }

        $perVillage = (int) ($this->appConfig['people_per_village'] ?? 30);
        $stockDays = (int) ($this->appConfig['start_stock_days'] ?? 7);
        $ugodiyaDays = (int) ($this->appConfig['ugodiya_days_reserve'] ?? 10);
        $buffer = (float) ($this->appConfig['start_stock_buffer'] ?? 1.2);
        $cap = (int) $world['population_cap'];
        $needs = $this->loadClickNeeds();
        $kcalPerDay = $needs['kcal_day'];
        $waterPerDay = $needs['water_day'];
        $villages = count(self::SMALL_START_PROVINCES);
        $startPop = (int) $world['population'];
        $seed = isset($world['map_seed']) ? (int) $world['map_seed'] : 0;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM chronicle WHERE world_id = ?')->execute([$worldId]);
            $this->clearWorldEconomy($worldId);
            $this->pdo->prepare(
                'UPDATE worlds SET day = 1, month = 1, year = 1 WHERE id = ? AND user_id = ?'
            )->execute([$worldId, $userId]);
            $this->pdo->prepare(
                'UPDATE people SET energy = 100, status = \'alive\' WHERE world_id = ?'
            )->execute([$worldId]);

            $settlementStmt = $this->pdo->prepare(
                'SELECT id, province_id FROM settlements WHERE world_id = ? ORDER BY id'
            );
            $settlementStmt->execute([$worldId]);
            $settlementRows = $settlementStmt->fetchAll();

            foreach ($settlementRows as $row) {
                $settlementId = (int) $row['id'];
                $this->pdo->prepare('DELETE FROM settlement_stock WHERE settlement_id = ?')
                    ->execute([$settlementId]);
                $this->pdo->prepare('DELETE FROM settlement_ugodiya WHERE settlement_id = ?')
                    ->execute([$settlementId]);
                $this->pdo->prepare('DELETE FROM settlement_plots WHERE settlement_id = ?')
                    ->execute([$settlementId]);
                $housingCap = (int) ($this->appConfig['housing_capacity'] ?? 40);
                $this->pdo->prepare(
                    'UPDATE settlements SET housing_kind = \'dormitory\', housing_capacity = ? WHERE id = ?'
                )->execute([$housingCap, $settlementId]);
                $this->seedSettlementStock(
                    $settlementId,
                    $perVillage,
                    $kcalPerDay,
                    $waterPerDay,
                    $stockDays,
                    $buffer
                );
                $this->seedVillageLayout($settlementId, $perVillage);
                $this->assignVillageJobs($settlementId, (int) $row['province_id']);
                $this->bootstrapClickEconomy($worldId, $settlementId);
            }

            $this->insertWorldCreatedChronicle(
                $worldId,
                $villages,
                $perVillage,
                $startPop,
                $stockDays,
                $buffer,
                $cap,
                $seed,
                true
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getOwned($worldId, $userId) ?? [];
    }

    public function chronicle(int $worldId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.day, c.month, c.year, c.event_type, c.message, c.created_at,
                    c.settlement_id, s.name AS settlement_name
             FROM chronicle c
             LEFT JOIN settlements s ON s.id = c.settlement_id
             WHERE c.world_id = ?
             ORDER BY c.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $worldId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function settlements(int $worldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, kind, is_capital, pos_x, pos_y, population,
                    province_id, housing_kind, housing_capacity
             FROM settlements WHERE world_id = ? ORDER BY id'
        );
        $stmt->execute([$worldId]);
        $rows = $stmt->fetchAll();

        $seedStmt = $this->pdo->prepare('SELECT map_seed FROM worlds WHERE id = ? LIMIT 1');
        $seedStmt->execute([$worldId]);
        $mapSeed = $seedStmt->fetchColumn();
        $mapSeed = $mapSeed !== false && $mapSeed !== null ? (int) $mapSeed : null;

        $stockStmt = $this->pdo->prepare(
            'SELECT sku_key, qty FROM settlement_stock WHERE settlement_id = ? ORDER BY sku_key'
        );
        $jobStmt = $this->pdo->prepare(
            'SELECT job_key, job_crop, COUNT(*) AS cnt
             FROM people WHERE settlement_id = ? AND status = \'alive\'
             GROUP BY job_key, job_crop ORDER BY job_key, job_crop'
        );
        $labels = $this->catalogLabels();
        foreach ($rows as &$row) {
            $sid = (int) $row['id'];
            $stockStmt->execute([$sid]);
            $row['stock'] = $this->enrichInventoryRows($stockStmt->fetchAll(), $labels);
            $fruitPair = $this->fruitPairForProvince((int) ($row['province_id'] ?? 0));
            $row['ugodiya'] = $this->ugodiyaCropLabels($fruitPair, $labels);
            $jobStmt->execute([$sid]);
            $row['jobs'] = $this->formatJobSummary($jobStmt->fetchAll(), $labels);
            $mods = $this->provinceGatherModifiers($mapSeed, (int) ($row['province_id'] ?? 0));
            $row['gather_mod_kcal'] = $mods['kcal'];
            $row['gather_mod_water'] = $mods['water'];
        }
        unset($row);

        return $rows;
    }

    public function peopleCount(int $worldId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM people WHERE world_id = ? AND status = \'alive\''
        );
        $stmt->execute([$worldId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function companies(int $worldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.code, c.title, c.kind, c.workers, c.credit_principal, c.credit_rate,
                    c.has_grossbook, c.settlement_id, w.balance
             FROM companies c
             LEFT JOIN wallets w
               ON w.world_id = c.world_id AND w.owner_type = \'company\' AND w.owner_id = c.id
             WHERE c.world_id = ?
             ORDER BY c.id'
        );
        $stmt->execute([$worldId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function treasuryWallet(int $worldId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, balance, settlement_id FROM wallets
             WHERE world_id = ? AND owner_type = \'treasury\' LIMIT 1'
        );
        $stmt->execute([$worldId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Монитор деревни: население, фирмы, ресурсы, финансы, сделки.
     *
     * @return array<string, mixed>
     */
    public function settlementMonitor(int $worldId, int $settlementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, kind, is_capital, population, province_id,
                    housing_kind, housing_capacity
             FROM settlements WHERE id = ? AND world_id = ? LIMIT 1'
        );
        $stmt->execute([$settlementId, $worldId]);
        $settlement = $stmt->fetch();
        if (!$settlement) {
            throw new \RuntimeException('Деревня не найдена', 404);
        }

        $aliveStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM people WHERE settlement_id = ? AND status = \'alive\''
        );
        $aliveStmt->execute([$settlementId]);
        $alive = (int) $aliveStmt->fetchColumn();

        $employedStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM people
             WHERE settlement_id = ? AND status = \'alive\'
               AND job_key IS NOT NULL AND job_key <> \'\''
        );
        $employedStmt->execute([$settlementId]);
        $employed = (int) $employedStmt->fetchColumn();

        $energyStmt = $this->pdo->prepare(
            'SELECT AVG(energy) FROM people WHERE settlement_id = ? AND status = \'alive\''
        );
        $energyStmt->execute([$settlementId]);
        $avgEnergy = round((float) $energyStmt->fetchColumn(), 1);

        $jobStmt = $this->pdo->prepare(
            'SELECT job_key, job_crop, COUNT(*) AS cnt
             FROM people WHERE settlement_id = ? AND status = \'alive\'
             GROUP BY job_key, job_crop ORDER BY cnt DESC, job_key'
        );
        $jobStmt->execute([$settlementId]);
        $labels = $this->catalogLabels();
        $jobs = $this->formatJobSummary($jobStmt->fetchAll(), $labels);

        $housingCap = (int) ($settlement['housing_capacity'] ?? 0);
        $housingFree = max(0, $housingCap - $alive);
        $layout = $this->ensureAndListLayout($settlementId, $alive);

        $companies = array_values(array_filter(
            $this->companies($worldId),
            static fn (array $c): bool => (int) ($c['settlement_id'] ?? 0) === $settlementId
        ));

        $firmCount = 0;
        $creditSum = 0.0;
        $firmCash = 0.0;
        foreach ($companies as $c) {
            if (($c['kind'] ?? '') === 'firm') {
                $firmCount++;
                $creditSum += (float) ($c['credit_principal'] ?? 0);
                $firmCash += (float) ($c['balance'] ?? 0);
            }
        }

        $stockStmt = $this->pdo->prepare(
            'SELECT sku_key, qty FROM settlement_stock WHERE settlement_id = ? ORDER BY sku_key'
        );
        $stockStmt->execute([$settlementId]);
        $stock = $this->enrichInventoryRows($stockStmt->fetchAll(), $labels);

        $fruitPair = $this->fruitPairForProvince((int) ($settlement['province_id'] ?? 0));
        $ugodiya = $this->ugodiyaCropLabels($fruitPair, $labels);

        $treasury = $this->walletByType($worldId, 'treasury', $settlementId);
        $bankCompany = null;
        $bankWallet = null;
        foreach ($companies as $c) {
            if (($c['kind'] ?? '') === 'bank') {
                $bankCompany = $c;
                $bankWallet = [
                    'balance' => (float) ($c['balance'] ?? 0),
                    'company_id' => (int) $c['id'],
                    'code' => $c['code'],
                ];
                break;
            }
        }

        $personCashStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(w.balance), 0) FROM wallets w
             WHERE w.world_id = ? AND w.settlement_id = ? AND w.owner_type = \'person\''
        );
        $personCashStmt->execute([$worldId, $settlementId]);
        $personCash = (float) $personCashStmt->fetchColumn();

        $treasuryBal = (float) ($treasury['balance'] ?? 0);
        $bankBal = (float) ($bankWallet['balance'] ?? 0);
        $moneyMass = $treasuryBal + $bankBal + $firmCash + $personCash;

        $ledger = $this->ledgerForSettlement($worldId, $settlementId, 40);
        $people = $this->peopleForSettlement($settlementId, $labels);
        $companies = $this->attachCompanyStaff($companies, $people);

        return [
            'settlement' => $settlement,
            'population' => [
                'alive' => $alive,
                'employed' => $employed,
                'unemployed' => max(0, $alive - $employed),
                'avg_energy' => $avgEnergy,
                'housing_kind' => $settlement['housing_kind'] ?? null,
                'housing_capacity' => $housingCap,
                'housing_occupied' => $alive,
                'housing_free' => $housingFree,
                'jobs' => $jobs,
                'people' => $people,
            ],
            'companies' => $companies,
            'companies_summary' => [
                'total' => count($companies),
                'firms' => $firmCount,
                'credit_sum' => round($creditSum, 2),
                'firm_cash_sum' => round($firmCash, 2),
            ],
            'resources' => [
                'stock' => $stock,
                'ugodiya' => $ugodiya,
            ],
            'layout' => $layout,
            'finance' => [
                'treasury' => $treasuryBal,
                'bank' => $bankBal,
                'bank_code' => $bankWallet['code'] ?? 'ЧБ',
                'firms_cash' => round($firmCash, 2),
                'people_cash' => round($personCash, 2),
                'money_mass' => round($moneyMass, 2),
                'note' => 'Стартовый кредит фирмам — от ЧБ; капитал ЧБ — из казны. Сделки в ledger.',
            ],
            'ledger' => $ledger,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function ledgerForSettlement(int $worldId, int $settlementId, int $limit = 40): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, day, month, year, kind, amount, note, company_id, created_at
             FROM ledger_entries
             WHERE world_id = ? AND (settlement_id = ? OR settlement_id IS NULL)
             ORDER BY id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $worldId, PDO::PARAM_INT);
        $stmt->bindValue(2, $settlementId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function walletByType(int $worldId, string $ownerType, int $settlementId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, balance, settlement_id, owner_type, owner_id FROM wallets
             WHERE world_id = ? AND owner_type = ? AND settlement_id = ?
             LIMIT 1'
        );
        $stmt->execute([$worldId, $ownerType, $settlementId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Срез C: календарь + сбор → склад → рацион → энергия. */
    public function advanceDay(int $worldId, int $userId): array
    {
        $world = $this->getOwned($worldId, $userId);
        if (!$world) {
            throw new \RuntimeException('Мир не найден', 404);
        }

        $day = (int) $world['day'];
        $month = (int) $world['month'];
        $year = (int) $world['year'];

        [$day, $month, $year] = $this->advanceCalendar($day, $month, $year);

        $needs = $this->loadClickNeeds();
        $kcalPerDay = $needs['kcal_day'];
        $waterPerDay = $needs['water_day'];
        $penaltyPartial = (int) ($this->appConfig['tick_energy_penalty_partial'] ?? 25);
        $penaltyHungry = (int) ($this->appConfig['tick_energy_penalty_hungry'] ?? 40);
        $kcalMap = $this->fruitKcalMap();
        $mapSeed = isset($world['map_seed']) ? (int) $world['map_seed'] : null;

        $restDay = (int) ($this->appConfig['rest_day_in_week'] ?? 4);
        $cal = $this->calendarLimits();
        $dayInWeek = (($day - 1) % $cal['days_per_week']) + 1;
        $isRestDay = $dayInWeek === $restDay;

        $this->pdo->beginTransaction();
        try {
            $tick = $this->runDayTick(
                $worldId,
                $kcalPerDay,
                $waterPerDay,
                $penaltyPartial,
                $penaltyHungry,
                $kcalMap,
                $mapSeed,
                $isRestDay
            );

            $this->pdo->prepare(
                'UPDATE worlds SET day = ?, month = ?, year = ? WHERE id = ? AND user_id = ?'
            )->execute([$day, $month, $year, $worldId, $userId]);

            if ($day === 1) {
                $this->chargeMonthlyAccountFees($worldId, $day, $month, $year);
            }

            $labels = $this->catalogLabels();
            foreach ($tick['settlements'] as $settlementTick) {
                $this->insertChronicle(
                    $worldId,
                    $day,
                    $month,
                    $year,
                    'day_tick',
                    $this->formatSettlementTickMessage($settlementTick, $labels),
                    (int) $settlementTick['settlement_id']
                );
            }

            $this->insertChronicle(
                $worldId,
                $day,
                $month,
                $year,
                'day_summary',
                $this->formatTickSummaryMessage($day, $month, $year, $tick),
                null
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getOwned($worldId, $userId) ?? [];
    }

    /**
     * @return array{
     *   alive: int,
     *   full: int,
     *   partial: int,
     *   hungry: int,
     *   gather_kcal: float,
     *   gather_water: float,
     *   gather_pieces: array<string, int>,
     *   work_pieces: array<string, int>,
     *   work_consumed: array<string, int>,
     *   ration_kcal: float,
     *   ration_water: float,
     *   ration_pieces: array<string, int>,
     *   stock_pieces: array<string, int>,
     *   stock_water: float,
     *   settlements: list<array<string, mixed>>,
     *   avg_energy: float,
     *   is_rest_day: bool
     * }
     */
    private function runDayTick(
        int $worldId,
        float $kcalNeed,
        float $waterNeed,
        int $penaltyPartial,
        int $penaltyHungry,
        array $kcalMap,
        ?int $mapSeed,
        bool $isRestDay = false
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.name, s.province_id,
                    (SELECT COUNT(*) FROM people p WHERE p.settlement_id = s.id AND p.status = \'alive\') AS alive
             FROM settlements s
             WHERE s.world_id = ?
             ORDER BY s.id'
        );
        $stmt->execute([$worldId]);
        $settlements = $stmt->fetchAll();

        $totals = [
            'alive' => 0,
            'full' => 0,
            'partial' => 0,
            'hungry' => 0,
            'gather_kcal' => 0.0,
            'gather_water' => 0.0,
            'gather_pieces' => [],
            'work_pieces' => [],
            'work_consumed' => [],
            'ration_kcal' => 0.0,
            'ration_water' => 0.0,
            'ration_pieces' => [],
            'stock_pieces' => [],
            'stock_water' => 0.0,
            'energy_sum' => 0,
            'settlements' => [],
            'is_rest_day' => $isRestDay,
        ];

        $peopleStmt = $this->pdo->prepare(
            'SELECT id, energy FROM people WHERE settlement_id = ? AND status = \'alive\' ORDER BY id'
        );
        $energyUpd = $this->pdo->prepare('UPDATE people SET energy = ? WHERE id = ?');

        foreach ($settlements as $row) {
            $settlementId = (int) $row['id'];
            $alive = (int) $row['alive'];
            if ($alive <= 0) {
                continue;
            }

            $settlementTick = [
                'settlement_id' => $settlementId,
                'settlement_name' => (string) $row['name'],
                'is_rest_day' => $isRestDay,
                'gather_water' => 0.0,
                'gather_pieces' => [],
                'work_pieces' => [],
                'work_consumed' => [],
                'work_lines' => [],
                'ration_water' => 0.0,
                'ration_pieces' => [],
                'stock_pieces' => [],
                'stock_water' => 0.0,
                'full' => 0,
                'partial' => 0,
                'hungry' => 0,
                'alive' => $alive,
                'energy_sum' => 0,
            ];

            $fruitPair = $this->fruitPairForProvince((int) $row['province_id']);
            $stock = $this->loadSettlementInventory('settlement_stock', $settlementId);
            $mods = $this->provinceGatherModifiers($mapSeed, (int) $row['province_id']);
            $jobCounts = $this->jobCountsForSettlement($settlementId);

            if (!$isRestDay) {
                // Фрукты — ЯблПром (gatherer); вода больше не «всем селом», а Водосбор в labor.
                $gathered = $this->harvestFruitOnly(
                    $stock,
                    $fruitPair,
                    $alive,
                    $kcalNeed,
                    $kcalMap,
                    $mods['kcal']
                );
                $totals['gather_kcal'] += $gathered['kcal'];
                $this->mergePieceMaps($totals['gather_pieces'], $gathered['pieces']);
                $settlementTick['gather_pieces'] = $gathered['pieces'];

                $labor = $this->applyLaborProduction($stock, $jobCounts);
                $settlementTick['work_pieces'] = $labor['produced'];
                $settlementTick['work_consumed'] = $labor['consumed'];
                $settlementTick['work_lines'] = $labor['lines'];
                $settlementTick['gather_water'] = (float) ($labor['produced']['water_1l'] ?? 0);
                $totals['gather_water'] += $settlementTick['gather_water'];
                $this->mergePieceMaps($totals['work_pieces'], $labor['produced']);
                $this->mergePieceMaps($totals['work_consumed'], $labor['consumed']);
            } else {
                // Выходной: промышленность стоит; харчевня «открыта» (пока лог).
                $tavernN = (int) ($jobCounts['tavern'] ?? 0);
                if ($tavernN > 0) {
                    $settlementTick['work_lines'][] = sprintf('харчевня: открыта (работников %d)', $tavernN);
                }
                $settlementTick['work_lines'][] = 'выходной: добыча/промка на паузе';
            }

            $peopleStmt->execute([$settlementId]);
            $people = $peopleStmt->fetchAll();

            foreach ($people as $personIndex => $person) {
                $totals['alive']++;
                [$kcalGot, $waterGot, $rationPieces] = $this->rationPerson(
                    $stock,
                    $kcalNeed,
                    $waterNeed,
                    $kcalMap,
                    $fruitPair,
                    (int) $personIndex
                );
                $totals['ration_kcal'] += $kcalGot;
                $totals['ration_water'] += $waterGot;
                $this->mergePieceMaps($totals['ration_pieces'], $rationPieces);
                $settlementTick['ration_water'] += $waterGot;
                $this->mergePieceMaps($settlementTick['ration_pieces'], $rationPieces);

                $energy = (int) $person['energy'];
                if ($kcalGot + 0.001 >= $kcalNeed && $waterGot + 0.001 >= $waterNeed) {
                    $energy = 100;
                    $totals['full']++;
                    $settlementTick['full']++;
                } elseif ($kcalGot >= $kcalNeed * 0.5 || $waterGot >= $waterNeed * 0.5) {
                    $energy = max(0, $energy - $penaltyPartial);
                    $totals['partial']++;
                    $settlementTick['partial']++;
                } else {
                    $energy = max(0, $energy - $penaltyHungry);
                    $totals['hungry']++;
                    $settlementTick['hungry']++;
                }

                $energyUpd->execute([$energy, (int) $person['id']]);
                $totals['energy_sum'] += $energy;
                $settlementTick['energy_sum'] += $energy;
            }

            $this->saveSettlementInventory('settlement_stock', $settlementId, $stock);
            $settlementTick['stock_pieces'] = $this->inventoryPieceTotals($stock);
            $settlementTick['stock_water'] = (float) ($stock['water_1l'] ?? 0.0);
            $settlementTick['avg_energy'] = $alive > 0
                ? round($settlementTick['energy_sum'] / $alive, 1)
                : 0.0;
            unset($settlementTick['energy_sum']);
            $totals['settlements'][] = $settlementTick;

            $this->mergePieceMaps($totals['stock_pieces'], $settlementTick['stock_pieces']);
            $totals['stock_water'] += $settlementTick['stock_water'];
        }

        $totals['avg_energy'] = $totals['alive'] > 0
            ? round($totals['energy_sum'] / $totals['alive'], 1)
            : 0.0;
        unset($totals['energy_sum']);

        return $totals;
    }

    /** @return array<string, int> job_key => count */
    private function jobCountsForSettlement(int $settlementId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT job_key, COUNT(*) AS cnt
             FROM people WHERE settlement_id = ? AND status = \'alive\'
             GROUP BY job_key'
        );
        $stmt->execute([$settlementId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['job_key']] = (int) $row['cnt'];
        }
        return $out;
    }

    /**
     * Сбор фруктов угодий (без абстрактной воды).
     *
     * @param array<string, float> $stock
     * @param list<string> $fruitPair
     * @param array<string, float> $kcalMap
     * @return array{kcal: float, pieces: array<string, int>}
     */
    private function harvestFruitOnly(
        array &$stock,
        array $fruitPair,
        int $alive,
        float $kcalPerDay,
        array $kcalMap,
        float $kcalMod
    ): array {
        if ($alive <= 0) {
            return ['kcal' => 0.0, 'pieces' => []];
        }
        $cropSurplus = (float) ($this->appConfig['gather_crop_surplus_ratio'] ?? 1.25);
        $pieces = [];
        $movedKcal = 0.0;
        $primarySku = $fruitPair[0] ?? null;
        if ($primarySku !== null) {
            $kcalTarget = $alive * $kcalPerDay * $cropSurplus * $kcalMod;
            $unitKcal = $kcalMap[$primarySku] ?? 100.0;
            if ($unitKcal > 0) {
                $qty = (int) ceil($kcalTarget / $unitKcal);
                $stock[$primarySku] = ($stock[$primarySku] ?? 0.0) + $qty;
                $pieces[$primarySku] = $qty;
                $movedKcal += $qty * $unitKcal;
            }
        }
        return ['kcal' => $movedKcal, 'pieces' => $pieces];
    }

    /**
     * Труд по ролям → склад. Нормы черновые (6 ч смены).
     *
     * @param array<string, float> $stock
     * @param array<string, int> $jobs
     * @return array{produced: array<string, int>, consumed: array<string, int>, lines: list<string>}
     */
    private function applyLaborProduction(array &$stock, array $jobs): array
    {
        $produced = [];
        $consumed = [];
        $lines = [];

        $add = static function (array &$map, string $sku, float $qty) use (&$stock): void {
            if ($qty <= 0) {
                return;
            }
            $stock[$sku] = ($stock[$sku] ?? 0.0) + $qty;
            $map[$sku] = (int) (($map[$sku] ?? 0) + (int) round($qty));
        };
        $take = static function (array &$map, string $sku, float $need) use (&$stock): float {
            $have = (float) ($stock[$sku] ?? 0.0);
            if ($have + 0.0001 < $need) {
                return 0.0;
            }
            $stock[$sku] = $have - $need;
            $map[$sku] = (int) (($map[$sku] ?? 0) + (int) round($need));
            return $need;
        };

        // Водосбор: л/раб.день
        $n = (int) ($jobs['water_carrier'] ?? 0);
        if ($n > 0) {
            $qty = $n * 18;
            $add($produced, 'water_1l', $qty);
            $lines[] = sprintf('Водосбор: +%d л воды', $qty);
        }

        // Глина
        $n = (int) ($jobs['clay_digger'] ?? 0);
        if ($n > 0) {
            $qty = $n * 5;
            $add($produced, 'clay_kg', $qty);
            $lines[] = sprintf('ГонПром: +%d кг глины', $qty);
        }

        // Гончар: 1 кг глины → 1 горшок 1л, до 6/раб
        $n = (int) ($jobs['potter'] ?? 0);
        if ($n > 0) {
            $max = $n * 6;
            $made = 0;
            for ($i = 0; $i < $max; $i++) {
                if ($take($consumed, 'clay_kg', 1) < 1) {
                    break;
                }
                $add($produced, 'pot_1l', 1);
                $made++;
            }
            $lines[] = sprintf('ГонПром: +%d горшков 1л (глина −%d)', $made, $made);
        }

        // Лес: топор → 4 бревна + кора + ветки / раб
        $n = (int) ($jobs['woodcutter'] ?? 0);
        if ($n > 0) {
            $logs = $n * 4;
            $bark = $n * 2;
            $branch = $n * 6;
            $add($produced, 'log', $logs);
            $add($produced, 'bark', $bark);
            $add($produced, 'branch', $branch);
            $lines[] = sprintf('ЛесПром: +%d брёвен, +%d коры, +%d веток', $logs, $bark, $branch);
        }

        // Камень
        $n = (int) ($jobs['stone_digger'] ?? 0);
        if ($n > 0) {
            $qty = $n * 3;
            $add($produced, 'stone', $qty);
            $lines[] = sprintf('КамДоб: +%d камня', $qty);
        }

        // Пшеница / хлопок / фермы
        $n = (int) ($jobs['wheat_farmer'] ?? 0);
        if ($n > 0) {
            $qty = $n * 5;
            $add($produced, 'wheat', $qty);
            $lines[] = sprintf('ПшАгро: +%d пшеницы', $qty);
        }
        $n = (int) ($jobs['cotton_farmer'] ?? 0);
        if ($n > 0) {
            $qty = $n * 2;
            $add($produced, 'cotton_cloth', $qty);
            $lines[] = sprintf('ХлопАгро: +%d ткани', $qty);
        }
        $n = (int) ($jobs['sheep_farmer'] ?? 0);
        if ($n > 0) {
            $qty = $n * 1;
            $add($produced, 'wool', $qty);
            $lines[] = sprintf('ОвФерма: +%d шерсти', $qty);
        }
        $n = (int) ($jobs['cow_farmer'] ?? 0);
        if ($n > 0) {
            $qty = $n * 2;
            $add($produced, 'milk_1l', $qty);
            $lines[] = sprintf('КорФерма: +%d л молока', $qty);
        }
        $n = (int) ($jobs['chicken_farmer'] ?? 0);
        if ($n > 0) {
            $eggs = $n * 4;
            $feathers = $n * 1;
            $add($produced, 'egg', $eggs);
            $add($produced, 'feather', $feathers);
            $lines[] = sprintf('КурФерм: +%d яиц, +%d перьев', $eggs, $feathers);
        }

        // РемеслФабр: бумага из коры, ящик из веток, топор из камня
        $n = (int) ($jobs['craft_fab'] ?? 0);
        if ($n > 0) {
            $paper = 0;
            for ($i = 0; $i < $n * 3; $i++) {
                if ($take($consumed, 'bark', 2) < 2) {
                    break;
                }
                $add($produced, 'paper', 1);
                $paper++;
            }
            $crates = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($take($consumed, 'branch', 8) < 8) {
                    break;
                }
                $add($produced, 'crate_4x4', 1);
                $crates++;
            }
            $axes = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($take($consumed, 'stone', 1) < 1) {
                    break;
                }
                $add($produced, 'axe_stone', 1);
                $axes++;
            }
            $lines[] = sprintf('РемеслФабр: бумага +%d, ящик +%d, топор +%d', $paper, $crates, $axes);
        }

        // Пекарня: 2 пшеницы → 4 лепёшки
        $n = (int) ($jobs['baker'] ?? 0);
        if ($n > 0) {
            $bread = 0;
            for ($i = 0; $i < $n * 2; $i++) {
                if ($take($consumed, 'wheat', 2) < 2) {
                    break;
                }
                $add($produced, 'flatbread', 4);
                $bread += 4;
            }
            $lines[] = sprintf('Пекарня: +%d лепёшек', $bread);
        }

        // Молокозавод: 2 л молока → 1 сыр
        $n = (int) ($jobs['dairy_worker'] ?? 0);
        if ($n > 0) {
            $cheese = 0;
            for ($i = 0; $i < $n * 2; $i++) {
                if ($take($consumed, 'milk_1l', 2) < 2) {
                    break;
                }
                $add($produced, 'cheese', 1);
                $cheese++;
            }
            $lines[] = sprintf('МолокоЗавод: +%d сыра', $cheese);
        }

        // ПрБюро: 1 бумага + 1 перо → 1 проект (если есть)
        $n = (int) ($jobs['designer'] ?? 0);
        if ($n > 0) {
            $proj = 0;
            for ($i = 0; $i < $n; $i++) {
                if (($stock['paper'] ?? 0) < 1) {
                    break;
                }
                $take($consumed, 'paper', 1);
                if (($stock['feather'] ?? 0) >= 1) {
                    $take($consumed, 'feather', 1);
                }
                $add($produced, 'building_project', 1);
                $proj++;
            }
            $lines[] = sprintf('ПрБюро: +%d проект(ов)', $proj);
        }

        // Швея: 1 ткань или шерсть → 1 рубаха
        $n = (int) ($jobs['tailor'] ?? 0);
        if ($n > 0) {
            $shirts = 0;
            for ($i = 0; $i < $n; $i++) {
                if (($stock['cotton_cloth'] ?? 0) >= 1) {
                    $take($consumed, 'cotton_cloth', 1);
                } elseif (($stock['wool'] ?? 0) >= 1) {
                    $take($consumed, 'wool', 1);
                } else {
                    break;
                }
                $add($produced, 'shirt', 1);
                $shirts++;
            }
            $lines[] = sprintf('ШвейФаб: +%d рубах', $shirts);
        }

        // Услуги без склада — только лог
        foreach (
            [
                'builder' => 'СтройКом: смена на ратуше',
                'carrier' => 'ТРКом: рейсы',
                'shopkeeper' => 'Магазин: торговый день',
                'banker' => 'ЧБ: обслуживание счетов',
                'chief' => 'Казна: учёт дня',
            ] as $job => $label
        ) {
            if ((int) ($jobs[$job] ?? 0) > 0) {
                $lines[] = $label;
            }
        }

        return ['produced' => $produced, 'consumed' => $consumed, 'lines' => $lines];
    }

    /**
     * Резерв ширины улицы на будущее (см) — на карте не рисуем шоссе,
     * только держим норму зазора между домами.
     * тротуар 2 + клумба 2 + 2×2 м туда + 2×2 м обратно + клумба 2 + тротуар 2 = 16 м.
     */
    private const LAYOUT_SIDEWALK_CM = 200.0;
    private const LAYOUT_FLOWER_CM = 200.0;
    private const LAYOUT_LANE_CM = 200.0;
    private const LAYOUT_LANES_PER_DIR = 2;
    private const LAYOUT_VERGE_CM = self::LAYOUT_SIDEWALK_CM + self::LAYOUT_FLOWER_CM; // 400
    private const LAYOUT_ROADWAY_CM =
        self::LAYOUT_LANE_CM * self::LAYOUT_LANES_PER_DIR * 2; // 800
    private const LAYOUT_STREET_CM =
        self::LAYOUT_VERGE_CM + self::LAYOUT_ROADWAY_CM + self::LAYOUT_VERGE_CM; // 1600

    /** Общежитие ×10 v2: комнаты 2×2, коридор 1 м, холл 4×2 → снаружи 12×5 м. */
    private const LAYOUT_DORM_FRONT_CM = 1200.0;
    private const LAYOUT_DORM_DEPTH_CM = 500.0;
    /** Ратуша: две офисные комнаты 5×5 → снаружи 10×5 м. */
    private const LAYOUT_RATUSHA_FRONT_CM = 1000.0;
    private const LAYOUT_RATUSHA_DEPTH_CM = 500.0;
    private const LAYOUT_SHOP_FRONT_CM = 400.0;
    private const LAYOUT_SHOP_DEPTH_CM = 300.0;
    private const LAYOUT_FIRM_FRONT_CM = 600.0;
    private const LAYOUT_FIRM_DEPTH_CM = 500.0;

    /** Холст редактора: нуль в центре; расширение только плюсиками по краям. */
    private const LAYOUT_ORIGIN_X_CM = 4000.0;
    private const LAYOUT_ORIGIN_Y_CM = 3000.0;
    private const LAYOUT_HALF_W_CM = 4000.0; // старт ±40 м → 80 м
    private const LAYOUT_HALF_H_CM = 3000.0; // старт ±30 м → 60 м
    /** @deprecated совместимость — см. origin ± half */
    private const LAYOUT_CANVAS_W_CM = self::LAYOUT_HALF_W_CM * 2;
    private const LAYOUT_CANVAS_H_CM = self::LAYOUT_HALF_H_CM * 2;

    /**
     * Редактор застройки: без нарисованных дорог.
     * Старые street-plots снимаем; здания оставляем редактируемыми.
     */
    public function seedVillageLayout(int $settlementId, int $population = 30): void
    {
        $sid = (int) $settlementId;

        // Убираем шоссе/переулки — расстояние между домами на будущее, не слой карты.
        $this->pdo->prepare(
            "DELETE FROM settlement_plots WHERE settlement_id = ? AND kind = 'street'"
        )->execute([$sid]);

        // Общаги → план v3 (холл=циркуляция, дверь во двор, двери-дуги).
        $dorms = $this->pdo->prepare(
            "SELECT id, width_cm, depth_cm, floor_plan_json FROM settlement_plots
             WHERE settlement_id = ? AND kind = 'dorm'"
        );
        $dorms->execute([$sid]);
        $updDorm = $this->pdo->prepare(
            'UPDATE settlement_plots
             SET width_cm = ?, depth_cm = ?, template_key = ?, floor_plan_json = ?,
                 building_sku_key = COALESCE(building_sku_key, ?)
             WHERE id = ?'
        );
        $dormPlan = $this->dormFloorPlanV2();
        $planJson = json_encode($dormPlan, JSON_UNESCAPED_UNICODE);
        foreach ($dorms->fetchAll() as $d) {
            $w = (float) ($d['width_cm'] ?? 0);
            $ver = 0;
            if (!empty($d['floor_plan_json'])) {
                $decoded = json_decode((string) $d['floor_plan_json'], true);
                $ver = (int) ($decoded['version'] ?? 0);
            }
            $needsSize = $w > 0 && abs($w - self::LAYOUT_DORM_FRONT_CM) > 1;
            $noPlan = empty($d['floor_plan_json']);
            $oldPlan = $ver < (int) ($dormPlan['version'] ?? 3);
            if ($needsSize || $noPlan || $oldPlan) {
                $updDorm->execute([
                    self::LAYOUT_DORM_FRONT_CM,
                    self::LAYOUT_DORM_DEPTH_CM,
                    'dorm_10',
                    $planJson,
                    'obshchezhitie_10',
                    (int) $d['id'],
                ]);
            }
        }

        // Ратуша → 10×5 м, две комнаты 5×5 (офисный модуль).
        $civics = $this->pdo->prepare(
            "SELECT id, width_cm, floor_plan_json, template_key, building_sku_key
             FROM settlement_plots
             WHERE settlement_id = ? AND (kind = 'civic' OR template_key = 'ratusha'
                OR building_sku_key = 'ratusha')"
        );
        $civics->execute([$sid]);
        $updCivic = $this->pdo->prepare(
            'UPDATE settlement_plots
             SET width_cm = ?, depth_cm = ?, kind = \'civic\', template_key = ?,
                 floor_plan_json = ?, building_sku_key = COALESCE(building_sku_key, ?)
             WHERE id = ?'
        );
        $ratushaPlan = $this->ratushaFloorPlanV2();
        $ratushaJson = json_encode($ratushaPlan, JSON_UNESCAPED_UNICODE);
        foreach ($civics->fetchAll() as $c) {
            $w = (float) ($c['width_cm'] ?? 0);
            $ver = 0;
            if (!empty($c['floor_plan_json'])) {
                $decoded = json_decode((string) $c['floor_plan_json'], true);
                $ver = (int) ($decoded['version'] ?? 0);
            }
            $needs = abs($w - self::LAYOUT_RATUSHA_FRONT_CM) > 1
                || empty($c['floor_plan_json'])
                || $ver < (int) ($ratushaPlan['version'] ?? 2);
            if ($needs) {
                $updCivic->execute([
                    self::LAYOUT_RATUSHA_FRONT_CM,
                    self::LAYOUT_RATUSHA_DEPTH_CM,
                    'ratusha',
                    $ratushaJson,
                    'ratusha',
                    (int) $c['id'],
                ]);
            }
        }

        $this->redistributeDormOccupancy($settlementId, $population);
        $cap = (int) $this->pdo->query(
            "SELECT COALESCE(SUM(capacity), 0) FROM settlement_plots
             WHERE settlement_id = {$sid} AND kind = 'dorm'"
        )->fetchColumn();
        if ($cap <= 0) {
            $cap = 40;
        }
        $this->pdo->prepare(
            'UPDATE settlements SET housing_kind = \'dormitory\', housing_capacity = ? WHERE id = ?'
        )->execute([$cap, $sid]);
    }

    /**
     * План общежития v3: 6+4 комнат, коридор+холл одним цветом, выход во двор.
     * door: wall + hinge + swing (out = из комнаты в циркуляцию / из холла во двор).
     */
    public function dormFloorPlanV2(): array
    {
        $els = [];
        for ($i = 0; $i < 6; $i++) {
            $els[] = [
                'id' => 'n' . $i,
                'type' => 'room',
                'label' => (string) ($i + 1),
                'x_m' => $i * 2.0,
                'y_m' => 0.0,
                'w_m' => 2.0,
                'd_m' => 2.0,
                'door' => ['wall' => 's', 'hinge' => 'w', 'swing' => 'out'],
            ];
        }
        for ($i = 0; $i < 4; $i++) {
            $els[] = [
                'id' => 's' . $i,
                'type' => 'room',
                'label' => (string) ($i + 7),
                'x_m' => $i * 2.0,
                'y_m' => 3.0,
                'w_m' => 2.0,
                'd_m' => 2.0,
                'door' => ['wall' => 'n', 'hinge' => 'w', 'swing' => 'out'],
            ];
        }
        // Холл = часть циркуляции (не «комната»), выход во двор на юг.
        $els[] = [
            'id' => 'hall',
            'type' => 'hall',
            'label' => '',
            'x_m' => 8.0,
            'y_m' => 3.0,
            'w_m' => 4.0,
            'd_m' => 2.0,
            'door' => ['wall' => 's', 'hinge' => 'w', 'swing' => 'out', 'exit' => true],
        ];
        $els[] = [
            'id' => 'cor',
            'type' => 'corridor',
            'label' => '',
            'x_m' => 0.0,
            'y_m' => 2.0,
            'w_m' => 12.0,
            'd_m' => 1.0,
            'door' => null,
        ];
        return [
            'version' => 3,
            'exterior_m' => ['w' => 12.0, 'd' => 5.0],
            'exit_to' => 'yard',
            'elements' => $els,
        ];
    }

    /**
     * Ратуша v2: сруб 10×5 м = две офисные комнаты 5×5, вход во двор из зала.
     */
    public function ratushaFloorPlanV2(): array
    {
        return [
            'version' => 2,
            'exterior_m' => ['w' => 10.0, 'd' => 5.0],
            'exit_to' => 'yard',
            'elements' => [
                [
                    'id' => 'sklad',
                    'type' => 'room',
                    'label' => 'склад',
                    'x_m' => 0.0,
                    'y_m' => 0.0,
                    'w_m' => 5.0,
                    'd_m' => 5.0,
                    'door' => ['wall' => 'e', 'hinge' => 'n', 'swing' => 'out'],
                ],
                [
                    'id' => 'zal',
                    'type' => 'room',
                    'label' => 'зал',
                    'x_m' => 5.0,
                    'y_m' => 0.0,
                    'w_m' => 5.0,
                    'd_m' => 5.0,
                    'door' => ['wall' => 's', 'hinge' => 'w', 'swing' => 'out', 'exit' => true],
                ],
            ],
        ];
    }

    /** @deprecated используй ratushaFloorPlanV2 */
    public function ratushaFloorPlanV1(): array
    {
        return $this->ratushaFloorPlanV2();
    }

    /** @return list<array<string,mixed>> */
    public function layoutTemplates(): array
    {
        return [
            [
                'key' => 'dorm_10',
                'kind' => 'dorm',
                'title' => 'Общежитие ×10',
                'width_m' => self::LAYOUT_DORM_FRONT_CM / 100,
                'depth_m' => self::LAYOUT_DORM_DEPTH_CM / 100,
                'capacity' => 10,
                'sku' => 'obshchezhitie_10',
                'floor_plan' => $this->dormFloorPlanV2(),
            ],
            [
                'key' => 'ratusha',
                'kind' => 'civic',
                'title' => 'Ратуша',
                'width_m' => self::LAYOUT_RATUSHA_FRONT_CM / 100,
                'depth_m' => self::LAYOUT_RATUSHA_DEPTH_CM / 100,
                'capacity' => null,
                'sku' => 'ratusha',
                'build_status' => 'building',
                'floor_plan' => $this->ratushaFloorPlanV2(),
            ],
            [
                'key' => 'shop',
                'kind' => 'shop',
                'title' => 'Магазин',
                'width_m' => self::LAYOUT_SHOP_FRONT_CM / 100,
                'depth_m' => self::LAYOUT_SHOP_DEPTH_CM / 100,
                'capacity' => null,
                'sku' => null,
                'company' => 'Магазин',
                'build_status' => 'building',
                'floor_plan' => null,
            ],
            [
                'key' => 'firm_lot',
                'kind' => 'firm',
                'title' => 'Участок фирмы',
                'width_m' => self::LAYOUT_FIRM_FRONT_CM / 100,
                'depth_m' => self::LAYOUT_FIRM_DEPTH_CM / 100,
                'capacity' => null,
                'sku' => null,
                'build_status' => 'building',
                'floor_plan' => null,
            ],
            [
                // Плитка улицы-резерва 16×16 м (канон профиля); kind=road — не стираем при сиде.
                'key' => 'road_tile',
                'kind' => 'road',
                'title' => 'Дорога 16×16',
                'width_m' => self::LAYOUT_STREET_CM / 100,
                'depth_m' => self::LAYOUT_STREET_CM / 100,
                'capacity' => null,
                'sku' => null,
                'road_mode' => 'straight',
                'floor_plan' => null,
            ],
            [
                // Перекрёсток: разметка с 4 сторон, центр без сквозных пунктиров.
                'key' => 'road_cross',
                'kind' => 'road',
                'title' => 'Перекрёсток 16×16',
                'width_m' => self::LAYOUT_STREET_CM / 100,
                'depth_m' => self::LAYOUT_STREET_CM / 100,
                'capacity' => null,
                'sku' => null,
                'road_mode' => 'cross',
                'floor_plan' => null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureAndListLayout(int $settlementId, int $population): array
    {
        $this->seedVillageLayout($settlementId, $population);
        $this->ensureLayoutCanvasInitialized($settlementId);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM settlement_plots WHERE settlement_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$settlementId]);
        $plots = $stmt->fetchAll();
        $originX = self::LAYOUT_ORIGIN_X_CM;
        $originY = self::LAYOUT_ORIGIN_Y_CM;
        foreach ($plots as &$p) {
            $w = isset($p['width_cm']) ? (float) $p['width_cm'] : 0.0;
            $d = isset($p['depth_cm']) ? (float) $p['depth_cm'] : 0.0;
            $x = isset($p['x_cm']) ? (float) $p['x_cm'] : 0.0;
            $y = isset($p['y_cm']) ? (float) $p['y_cm'] : 0.0;
            $rot = (int) ($p['rotation_deg'] ?? 0);
            $p['rotation_deg'] = $rot;
            $p['flip_x'] = (int) ($p['flip_x'] ?? 0);
            $p['flip_y'] = (int) ($p['flip_y'] ?? 0);
            $p['is_pinned'] = (int) ($p['is_pinned'] ?? 0);
            $p['build_status'] = $this->normalizeBuildStatus($p['build_status'] ?? null);
            $p['company_code'] = isset($p['company_code']) && $p['company_code'] !== ''
                ? (string) $p['company_code']
                : null;
            $p['x_m'] = round($x / 100, 2);
            $p['y_m'] = round($y / 100, 2);
            $p['width_m'] = round($w / 100, 2);
            $p['depth_m'] = round($d / 100, 2);
            $p['cx_m'] = round(($x + $w / 2) / 100, 2);
            $p['cy_m'] = round(($y + $d / 2) / 100, 2);
            if (!empty($p['floor_plan_json'])) {
                $fp = json_decode((string) $p['floor_plan_json'], true);
                $p['floor_plan'] = is_array($fp) ? $fp : null;
            } else {
                $p['floor_plan'] = null;
            }
            unset($p['floor_plan_json']);
        }
        unset($p);

        $canvas = $this->loadLayoutCanvasCm($settlementId) ?? $this->defaultLayoutCanvasCm();
        $minX = $canvas['min_x_cm'];
        $minY = $canvas['min_y_cm'];
        $maxX = $canvas['max_x_cm'];
        $maxY = $canvas['max_y_cm'];

        $dorms = array_values(array_filter($plots, static fn ($p) => ($p['kind'] ?? '') === 'dorm'));
        $streetM = self::LAYOUT_STREET_CM / 100;
        return [
            'unit' => 'm',
            'editor' => true,
            'street_reserve_m' => $streetM,
            'street' => [
                'total_m' => $streetM,
                'lane_m' => self::LAYOUT_LANE_CM / 100,
                'lanes_per_dir' => self::LAYOUT_LANES_PER_DIR,
                'lanes' => self::LAYOUT_LANES_PER_DIR * 2,
                'verge_m' => self::LAYOUT_VERGE_CM / 100,
                'note' => 'Резерв зазора на будущее, на карте не рисуется',
            ],
            'street_m' => $streetM,
            'origin_x_m' => round($originX / 100, 2),
            'origin_y_m' => round($originY / 100, 2),
            'map_min_x_m' => round($minX / 100, 2),
            'map_min_y_m' => round($minY / 100, 2),
            'map_max_x_m' => round($maxX / 100, 2),
            'map_max_y_m' => round($maxY / 100, 2),
            'map_width_m' => round(($maxX - $minX) / 100, 2),
            'map_height_m' => round(($maxY - $minY) / 100, 2),
            'templates' => $this->layoutTemplates(),
            'plots' => $plots,
            'dorms' => $dorms,
            'note' => 'Редактор: холст расширяется плюсиками по краям. docs/village-layout-v1.md',
        ];
    }

    /**
     * Расширить холст в сторону edge на meters метров (сохраняется в settlements).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function expandLayoutCanvas(int $settlementId, int $population, array $body): array
    {
        $edge = strtolower(trim((string) ($body['edge'] ?? '')));
        $meters = (float) ($body['meters'] ?? 0);
        if (!in_array($edge, ['n', 's', 'e', 'w'], true)) {
            throw new \RuntimeException('edge: n|s|e|w', 400);
        }
        if ($meters < 0.1 || $meters > 200) {
            throw new \RuntimeException('meters: 0.1…200', 400);
        }
        $this->ensureLayoutCanvasInitialized($settlementId);
        $c = $this->loadLayoutCanvasCm($settlementId) ?? $this->defaultLayoutCanvasCm();
        $delta = $meters * 100.0;
        if ($edge === 'n') {
            $c['min_y_cm'] -= $delta;
        } elseif ($edge === 's') {
            $c['max_y_cm'] += $delta;
        } elseif ($edge === 'w') {
            $c['min_x_cm'] -= $delta;
        } else {
            $c['max_x_cm'] += $delta;
        }
        $this->saveLayoutCanvasCm($settlementId, $c);
        return $this->ensureAndListLayout($settlementId, $population);
    }

    /** @return array{min_x_cm: float, min_y_cm: float, max_x_cm: float, max_y_cm: float} */
    private function defaultLayoutCanvasCm(): array
    {
        $ox = self::LAYOUT_ORIGIN_X_CM;
        $oy = self::LAYOUT_ORIGIN_Y_CM;
        return [
            'min_x_cm' => $ox - self::LAYOUT_HALF_W_CM,
            'min_y_cm' => $oy - self::LAYOUT_HALF_H_CM,
            'max_x_cm' => $ox + self::LAYOUT_HALF_W_CM,
            'max_y_cm' => $oy + self::LAYOUT_HALF_H_CM,
        ];
    }

    /** Границы для clamp без побочной записи в БД. */
    private function canvasForClamp(int $settlementId): array
    {
        return $this->loadLayoutCanvasCm($settlementId) ?? $this->defaultLayoutCanvasCm();
    }

    /**
     * Первый заход: зафиксировать дефолтный холст и вернуть «улетевшие» объекты внутрь.
     * Дальше размер меняют только плюсики по краям.
     */
    private function ensureLayoutCanvasInitialized(int $settlementId): void
    {
        if ($this->loadLayoutCanvasCm($settlementId) !== null) {
            return;
        }
        $c = $this->defaultLayoutCanvasCm();
        $this->saveLayoutCanvasCm($settlementId, $c);
        $stmt = $this->pdo->prepare(
            'SELECT id, x_cm, y_cm, width_cm, depth_cm FROM settlement_plots WHERE settlement_id = ?'
        );
        $stmt->execute([$settlementId]);
        $upd = $this->pdo->prepare(
            'UPDATE settlement_plots SET x_cm = ?, y_cm = ? WHERE id = ? AND settlement_id = ?'
        );
        foreach ($stmt->fetchAll() as $p) {
            $x = (float) ($p['x_cm'] ?? 0);
            $y = (float) ($p['y_cm'] ?? 0);
            $w = (float) ($p['width_cm'] ?? 0);
            $d = (float) ($p['depth_cm'] ?? 0);
            $cl = $this->clampPlotToCanvasCm($x, $y, $w, $d, $c);
            if (abs($cl['x'] - $x) > 0.01 || abs($cl['y'] - $y) > 0.01) {
                $upd->execute([$cl['x'], $cl['y'], (int) $p['id'], $settlementId]);
            }
        }
    }

    /** @return array{min_x_cm: float, min_y_cm: float, max_x_cm: float, max_y_cm: float}|null */
    private function loadLayoutCanvasCm(int $settlementId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT layout_canvas_json FROM settlements WHERE id = ?');
        $stmt->execute([$settlementId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['layout_canvas_json'])) {
            return null;
        }
        $j = json_decode((string) $row['layout_canvas_json'], true);
        if (!is_array($j)) {
            return null;
        }
        foreach (['min_x_cm', 'min_y_cm', 'max_x_cm', 'max_y_cm'] as $k) {
            if (!isset($j[$k]) || !is_numeric($j[$k])) {
                return null;
            }
        }
        return [
            'min_x_cm' => (float) $j['min_x_cm'],
            'min_y_cm' => (float) $j['min_y_cm'],
            'max_x_cm' => (float) $j['max_x_cm'],
            'max_y_cm' => (float) $j['max_y_cm'],
        ];
    }

    /** @param array{min_x_cm: float, min_y_cm: float, max_x_cm: float, max_y_cm: float} $c */
    private function saveLayoutCanvasCm(int $settlementId, array $c): void
    {
        $payload = json_encode([
            'min_x_cm' => round($c['min_x_cm'], 2),
            'min_y_cm' => round($c['min_y_cm'], 2),
            'max_x_cm' => round($c['max_x_cm'], 2),
            'max_y_cm' => round($c['max_y_cm'], 2),
        ], JSON_UNESCAPED_UNICODE);
        $this->pdo->prepare(
            'UPDATE settlements SET layout_canvas_json = ? WHERE id = ?'
        )->execute([$payload, $settlementId]);
    }

    /**
     * @param array{min_x_cm: float, min_y_cm: float, max_x_cm: float, max_y_cm: float} $c
     * @return array{x: float, y: float}
     */
    private function clampPlotToCanvasCm(float $x, float $y, float $w, float $d, array $c): array
    {
        $maxX = $c['max_x_cm'] - $w;
        $maxY = $c['max_y_cm'] - $d;
        if ($maxX < $c['min_x_cm']) {
            $x = $c['min_x_cm'];
        } else {
            $x = min(max($x, $c['min_x_cm']), $maxX);
        }
        if ($maxY < $c['min_y_cm']) {
            $y = $c['min_y_cm'];
        } else {
            $y = min(max($y, $c['min_y_cm']), $maxY);
        }
        return ['x' => $x, 'y' => $y];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createLayoutPlot(int $settlementId, int $population, array $body): array
    {
        $templateKey = trim((string) ($body['template_key'] ?? ''));
        $templates = [];
        foreach ($this->layoutTemplates() as $t) {
            $templates[$t['key']] = $t;
        }

        if ($templateKey === 'custom_firm') {
            $title = trim((string) ($body['title'] ?? 'Фирма'));
            if ($title === '') {
                $title = 'Фирма';
            }
            $wM = (float) ($body['width_m'] ?? 6);
            $dM = (float) ($body['depth_m'] ?? 5);
            if ($wM < 1 || $dM < 1) {
                throw new \InvalidArgumentException('Размер силуэта минимум 1×1 м');
            }
            $tpl = [
                'key' => 'custom_firm',
                'kind' => 'firm',
                'title' => $title,
                'width_m' => $wM,
                'depth_m' => $dM,
                'capacity' => null,
                'sku' => null,
                'build_status' => 'building',
                'floor_plan' => null,
            ];
        } else {
            if (!isset($templates[$templateKey])) {
                throw new \InvalidArgumentException('Неизвестный шаблон: ' . $templateKey);
            }
            $tpl = $templates[$templateKey];
            if (!empty($body['title'])) {
                $tpl['title'] = trim((string) $body['title']);
            }
        }

        $xM = (float) ($body['x_m'] ?? 2);
        $yM = (float) ($body['y_m'] ?? 2);
        $wM = (float) $tpl['width_m'];
        $dM = (float) $tpl['depth_m'];
        $rot = ((int) ($body['rotation_deg'] ?? 0)) % 360;
        if ($rot < 0) {
            $rot += 360;
        }
        if (!in_array($rot, [0, 90, 180, 270], true)) {
            $rot = (int) (round($rot / 90) * 90) % 360;
        }

        $canvas = $this->canvasForClamp($settlementId);
        $clamped = $this->clampPlotToCanvasCm($xM * 100, $yM * 100, $wM * 100, $dM * 100, $canvas);

        $buildStatus = $this->normalizeBuildStatus(
            $body['build_status'] ?? ($tpl['build_status'] ?? 'ready')
        );
        // Ратуша на старте — стройка (канон СтройКом с дня 1)
        if ($templateKey === 'ratusha' && !array_key_exists('build_status', $body)) {
            $buildStatus = 'building';
        }

        $plotKey = $templateKey . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $floorJson = !empty($tpl['floor_plan'])
            ? json_encode($tpl['floor_plan'], JSON_UNESCAPED_UNICODE)
            : null;

        $ord = (int) $this->pdo->query(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM settlement_plots WHERE settlement_id = '
            . (int) $settlementId
        )->fetchColumn();

        $ins = $this->pdo->prepare(
            'INSERT INTO settlement_plots
               (settlement_id, plot_key, kind, title, grid_col, grid_row, span_cols, span_rows,
                x_cm, y_cm, width_cm, depth_cm, rotation_deg, flip_x, flip_y, is_pinned, build_status,
                template_key, floor_plan_json,
                building_sku_key, company_code, capacity, occupied, sort_order)
             VALUES (?, ?, ?, ?, 0, 0, 1, 1, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        if (array_key_exists('company_code', $body)) {
            $rawCo = trim((string) ($body['company_code'] ?? ''));
            $companyCode = $rawCo === '' ? null : $rawCo;
        } else {
            $companyCode = isset($tpl['company']) && $tpl['company'] !== ''
                ? (string) $tpl['company']
                : null;
        }

        $ins->execute([
            $settlementId,
            $plotKey,
            $tpl['kind'],
            $tpl['title'],
            $clamped['x'],
            $clamped['y'],
            $wM * 100,
            $dM * 100,
            $rot,
            $buildStatus,
            $tpl['key'],
            $floorJson,
            $tpl['sku'] ?? null,
            $companyCode,
            $tpl['capacity'] ?? null,
            $ord,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->seedVillageLayout($settlementId, $population);
        return $this->getLayoutPlot($settlementId, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateLayoutPlot(int $settlementId, int $plotId, int $population, array $body): array
    {
        $row = $this->getLayoutPlotRow($settlementId, $plotId);
        if (!$row) {
            throw new \RuntimeException('Участок не найден', 404);
        }

        $x = array_key_exists('x_m', $body) ? (float) $body['x_m'] * 100 : (float) $row['x_cm'];
        $y = array_key_exists('y_m', $body) ? (float) $body['y_m'] * 100 : (float) $row['y_cm'];
        $w = array_key_exists('width_m', $body) ? (float) $body['width_m'] * 100 : (float) $row['width_cm'];
        $d = array_key_exists('depth_m', $body) ? (float) $body['depth_m'] * 100 : (float) $row['depth_cm'];
        $rot = array_key_exists('rotation_deg', $body)
            ? ((int) $body['rotation_deg']) % 360
            : (int) ($row['rotation_deg'] ?? 0);
        if ($rot < 0) {
            $rot += 360;
        }
        $pinned = array_key_exists('is_pinned', $body)
            ? ((int) ((bool) $body['is_pinned']))
            : (int) ($row['is_pinned'] ?? 0);
        $flipX = array_key_exists('flip_x', $body)
            ? ((int) ((bool) $body['flip_x']))
            : (int) ($row['flip_x'] ?? 0);
        $flipY = array_key_exists('flip_y', $body)
            ? ((int) ((bool) $body['flip_y']))
            : (int) ($row['flip_y'] ?? 0);
        $title = array_key_exists('title', $body)
            ? trim((string) $body['title'])
            : (string) $row['title'];
        if ($title === '') {
            $title = (string) $row['title'];
        }
        $buildStatus = array_key_exists('build_status', $body)
            ? $this->normalizeBuildStatus($body['build_status'])
            : $this->normalizeBuildStatus($row['build_status'] ?? 'ready');
        if (array_key_exists('company_code', $body)) {
            $rawCo = trim((string) ($body['company_code'] ?? ''));
            $companyCode = $rawCo === '' ? null : $rawCo;
        } else {
            $companyCode = isset($row['company_code']) && $row['company_code'] !== ''
                ? (string) $row['company_code']
                : null;
        }

        $canvas = $this->canvasForClamp($settlementId);
        $clamped = $this->clampPlotToCanvasCm($x, $y, $w, $d, $canvas);
        $x = $clamped['x'];
        $y = $clamped['y'];

        $this->pdo->prepare(
            'UPDATE settlement_plots
             SET x_cm = ?, y_cm = ?, width_cm = ?, depth_cm = ?,
                 rotation_deg = ?, flip_x = ?, flip_y = ?, is_pinned = ?, build_status = ?,
                 title = ?, company_code = ?
             WHERE id = ? AND settlement_id = ?'
        )->execute([
            $x, $y, $w, $d, $rot, $flipX, $flipY, $pinned, $buildStatus,
            $title, $companyCode, $plotId, $settlementId,
        ]);

        $this->seedVillageLayout($settlementId, $population);
        return $this->getLayoutPlot($settlementId, $plotId);
    }

    public function deleteLayoutPlot(int $settlementId, int $plotId, int $population): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM settlement_plots WHERE id = ? AND settlement_id = ?'
        );
        $stmt->execute([$plotId, $settlementId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Участок не найден', 404);
        }
        $this->seedVillageLayout($settlementId, $population);
    }

    /** @return array<string,mixed>|null */
    private function getLayoutPlotRow(int $settlementId, int $plotId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM settlement_plots WHERE id = ? AND settlement_id = ?'
        );
        $stmt->execute([$plotId, $settlementId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    public function getLayoutPlot(int $settlementId, int $plotId): array
    {
        $p = $this->getLayoutPlotRow($settlementId, $plotId);
        if (!$p) {
            throw new \RuntimeException('Участок не найден', 404);
        }
        $w = (float) ($p['width_cm'] ?? 0);
        $d = (float) ($p['depth_cm'] ?? 0);
        $x = (float) ($p['x_cm'] ?? 0);
        $y = (float) ($p['y_cm'] ?? 0);
        $p['rotation_deg'] = (int) ($p['rotation_deg'] ?? 0);
        $p['flip_x'] = (int) ($p['flip_x'] ?? 0);
        $p['flip_y'] = (int) ($p['flip_y'] ?? 0);
        $p['is_pinned'] = (int) ($p['is_pinned'] ?? 0);
        $p['build_status'] = $this->normalizeBuildStatus($p['build_status'] ?? null);
        $p['company_code'] = isset($p['company_code']) && $p['company_code'] !== ''
            ? (string) $p['company_code']
            : null;
        $p['x_m'] = round($x / 100, 2);
        $p['y_m'] = round($y / 100, 2);
        $p['width_m'] = round($w / 100, 2);
        $p['depth_m'] = round($d / 100, 2);
        $p['cx_m'] = round(($x + $w / 2) / 100, 2);
        $p['cy_m'] = round(($y + $d / 2) / 100, 2);
        if (!empty($p['floor_plan_json'])) {
            $fp = json_decode((string) $p['floor_plan_json'], true);
            $p['floor_plan'] = is_array($fp) ? $fp : null;
        } else {
            $p['floor_plan'] = null;
        }
        unset($p['floor_plan_json']);
        return $p;
    }

    private function normalizeBuildStatus(mixed $raw): string
    {
        $s = strtolower(trim((string) $raw));
        return $s === 'building' ? 'building' : 'ready';
    }

    private function redistributeDormOccupancy(int $settlementId, int $population): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, capacity FROM settlement_plots
             WHERE settlement_id = ? AND kind = 'dorm'
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$settlementId]);
        $dorms = $stmt->fetchAll();
        if ($dorms === []) {
            return;
        }
        $left = max(0, $population);
        $upd = $this->pdo->prepare('UPDATE settlement_plots SET occupied = ? WHERE id = ?');
        foreach ($dorms as $d) {
            $cap = (int) ($d['capacity'] ?? 10);
            $take = min($cap, $left);
            $upd->execute([$take, (int) $d['id']]);
            $left -= $take;
        }
    }

    /**
     * @param list<string> $fruitPair один фрукт деревни (массив из 1 sku)
     * @return list<array{job_key: string, job_crop: ?string}>
     */
    private function villageJobSlots(array $fruitPair): array
    {
        $slots = [];
        $perCrop = (int) ($this->appConfig['gatherers_per_crop'] ?? 2);
        $primaryCrop = $fruitPair[0] ?? 'apple';
        for ($i = 0; $i < $perCrop; $i++) {
            $slots[] = ['job_key' => 'gatherer', 'job_crop' => $primaryCrop];
        }

        // Канон Sheet «Причес старта» → 30 чел. ДрОбр влит в ЛесПром; бумага/краска → РемеслФабр.
        foreach (
            [
                ['job_key' => 'water_carrier'],
                ['job_key' => 'water_carrier'],
                ['job_key' => 'clay_digger'],
                ['job_key' => 'potter'],
                ['job_key' => 'woodcutter'],
                ['job_key' => 'woodcutter'],
                ['job_key' => 'builder'],
                ['job_key' => 'builder'],
                ['job_key' => 'carrier'],
                ['job_key' => 'carrier'],
                ['job_key' => 'designer'],
                ['job_key' => 'shopkeeper'],
                ['job_key' => 'banker'],
                // Казна без вождя на старте; слот → второй ремесленник (колодец ×2).
                ['job_key' => 'craft_fab'],
                ['job_key' => 'craft_fab'],
                ['job_key' => 'wheat_farmer'],
                ['job_key' => 'sheep_farmer'],
                ['job_key' => 'cow_farmer'],
                ['job_key' => 'cow_farmer'],
                ['job_key' => 'tailor'],
                ['job_key' => 'cotton_farmer'],
                ['job_key' => 'baker'],
                ['job_key' => 'dairy_worker'],
                ['job_key' => 'tavern'],
                ['job_key' => 'tavern'],
                ['job_key' => 'stone_digger'],
                ['job_key' => 'stone_digger'],
                ['job_key' => 'chicken_farmer'],
            ] as $slot
        ) {
            $slots[] = $slot;
        }

        return $slots;
    }

    private function assignVillageJobs(int $settlementId, int $provinceId): void
    {
        $slots = $this->villageJobSlots($this->fruitPairForProvince($provinceId));
        $stmt = $this->pdo->prepare(
            'SELECT id FROM people WHERE settlement_id = ? AND status = \'alive\' ORDER BY id'
        );
        $stmt->execute([$settlementId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $upd = $this->pdo->prepare('UPDATE people SET job_key = ?, job_crop = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $slot = $slots[(int) $i] ?? ['job_key' => 'gatherer', 'job_crop' => null];
            $upd->execute([
                $slot['job_key'],
                $slot['job_crop'] ?? null,
                (int) $id,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $companies
     * @param list<array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    private function attachCompanyStaff(array $companies, array $people): array
    {
        $byCode = [];
        foreach ($people as $person) {
            if (($person['status'] ?? '') !== 'alive') {
                continue;
            }
            $job = (string) ($person['job_key'] ?? '');
            $code = self::JOB_COMPANY[$job] ?? null;
            if ($code === null) {
                continue;
            }
            $byCode[$code][] = $person;
        }

        foreach ($companies as &$company) {
            $code = (string) ($company['code'] ?? '');
            $staff = $byCode[$code] ?? [];
            $company['description'] = (string) ($company['title'] ?? '');
            $company['staff'] = $staff;
            $company['staff_count'] = count($staff);
        }
        unset($company);

        return $companies;
    }

    /**
     * @param array<string, array{name_ru: string, unit: string, emoji: string}> $catalog
     * @return list<array<string, mixed>>
     */
    private function peopleForSettlement(int $settlementId, array $catalog): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.name, p.energy, p.status, p.job_key, p.job_crop,
                    COALESCE(w.balance, 0) AS cash
             FROM people p
             LEFT JOIN wallets w
               ON w.world_id = p.world_id
              AND w.owner_type = \'person\'
              AND w.owner_id = p.id
             WHERE p.settlement_id = ?
             ORDER BY p.status ASC, p.id ASC'
        );
        $stmt->execute([$settlementId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['job_key'] ?? '');
            $crop = $row['job_crop'] !== null ? (string) $row['job_crop'] : null;
            $jobLabel = self::JOB_LABELS[$key] ?? ($key !== '' ? $key : 'без роли');
            if ($key === 'gatherer' && $crop !== null && $crop !== '') {
                $cropLabel = $catalog[$crop]['name_ru'] ?? $crop;
                $jobLabel .= ' (' . $cropLabel . ')';
            }
            $energy = (int) $row['energy'];
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'energy' => $energy,
                'energy_band' => $energy >= 80 ? 'good' : ($energy >= 40 ? 'mid' : 'low'),
                'status' => (string) $row['status'],
                'job_key' => $key !== '' ? $key : null,
                'job_crop' => $crop,
                'job_label' => $jobLabel,
                'cash' => round((float) $row['cash'], 2),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{name_ru: string, unit: string, emoji: string}> $catalog
     * @return list<array{label: string, count: int}>
     */
    private function formatJobSummary(array $rows, array $catalog): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['job_key'];
            $crop = $row['job_crop'] !== null ? (string) $row['job_crop'] : null;
            $label = self::JOB_LABELS[$key] ?? $key;
            if ($key === 'gatherer' && $crop !== null && $crop !== '') {
                $cropLabel = $catalog[$crop]['name_ru'] ?? $crop;
                $label .= ' (' . $cropLabel . ')';
            }
            $out[] = [
                'label' => $label,
                'count' => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $fruitPair
     * @param array<string, array{name_ru: string, unit: string, emoji: string}> $labels
     * @return list<array<string, mixed>>
     */
    private function ugodiyaCropLabels(array $fruitPair, array $labels): array
    {
        $rows = [];
        foreach ($fruitPair as $sku) {
            $meta = $labels[$sku] ?? null;
            $rows[] = [
                'sku_key' => $sku,
                'name_ru' => $meta['name_ru'] ?? $sku,
                'emoji' => $meta['emoji'] ?? '',
                'unit' => $meta['unit'] ?? 'шт',
            ];
        }

        $water = $labels['water_1l'] ?? ['name_ru' => 'Вода', 'unit' => 'л', 'emoji' => '💧'];
        $rows[] = [
            'sku_key' => 'water_1l',
            'name_ru' => $water['name_ru'],
            'emoji' => $water['emoji'] ?? '💧',
            'unit' => $water['unit'] ?? 'л',
            'infinite' => true,
        ];

        return $rows;
    }

    /** @return array<string, float> */
    private function loadSettlementInventory(string $table, int $settlementId): array
    {
        if (!in_array($table, ['settlement_stock', 'settlement_ugodiya'], true)) {
            throw new \InvalidArgumentException('Unknown inventory table');
        }
        $stmt = $this->pdo->prepare("SELECT sku_key, qty FROM {$table} WHERE settlement_id = ?");
        $stmt->execute([$settlementId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[(string) $row['sku_key']] = (float) $row['qty'];
        }
        return $items;
    }

    /** @param array<string, float> $items */
    private function saveSettlementInventory(string $table, int $settlementId, array $items): void
    {
        if (!in_array($table, ['settlement_stock', 'settlement_ugodiya'], true)) {
            throw new \InvalidArgumentException('Unknown inventory table');
        }
        $upsert = $this->pdo->prepare(
            "INSERT INTO {$table} (settlement_id, sku_key, qty)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)"
        );
        foreach ($items as $sku => $qty) {
            $upsert->execute([$settlementId, $sku, round(max(0.0, $qty), 3)]);
        }
    }

    /** @return array<string, string> */
    private function itemEmojis(): array
    {
        return [
            'apple' => '🍎',
            'pear' => '🍐',
            'banana' => '🍌',
            'mandarin' => '🍊',
            'peach' => '🍑',
            'grape' => '🍇',
            'water_1l' => '💧',
            'pot_1l' => '🫙',
            'clay_kg' => '🧱',
            'log' => '🪵',
            'bark' => '🟫',
            'branch' => '🌿',
            'stone' => '🪨',
            'wheat' => '🌾',
            'cotton_cloth' => '🧵',
            'wool' => '🧶',
            'milk_1l' => '🥛',
            'egg' => '🥚',
            'feather' => '🪶',
            'paper' => '📄',
            'crate_4x4' => '📦',
            'axe_stone' => '🪓',
            'flatbread' => '🫓',
            'cheese' => '🧀',
            'building_project' => '📐',
            'shirt' => '👕',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function enrichInventoryRows(array $rows, array $labels): array
    {
        $emojis = $this->itemEmojis();
        foreach ($rows as &$item) {
            $sku = (string) $item['sku_key'];
            $meta = $labels[$sku] ?? null;
            $item['name_ru'] = $meta['name_ru'] ?? $sku;
            $item['unit'] = $meta['unit'] ?? '';
            $item['emoji'] = $meta['emoji'] ?? $emojis[$sku] ?? '';
        }
        unset($item);
        return $rows;
    }

    /**
     * @param array<string, float> $stock
     * @return array{0: float, 1: float}
     */
    private function stockTotals(array $stock, array $kcalMap): array
    {
        $kcal = 0.0;
        $water = (float) ($stock['water_1l'] ?? 0.0);
        foreach ($stock as $sku => $qty) {
            if ($sku === 'water_1l' || $sku === 'pot_1l' || $qty <= 0) {
                continue;
            }
            $unit = $kcalMap[$sku] ?? 0.0;
            if ($unit > 0) {
                $kcal += $qty * $unit;
            }
        }
        return [$kcal, $water];
    }

    /** @return array{kcal: float, water: float} */
    private function provinceGatherModifiers(?int $mapSeed, int $provinceId): array
    {
        if ($mapSeed === null || $provinceId <= 0) {
            return ['kcal' => 1.0, 'water' => 1.0];
        }
        $hash = crc32($mapSeed . ':' . $provinceId);
        return [
            'kcal' => round(0.9 + ($hash % 21) / 100.0, 2),
            'water' => round(0.9 + (($hash >> 8) % 21) / 100.0, 2),
        ];
    }

    /**
     * @return array<string, array{name_ru: string, unit: string, emoji: string}>
     */
    private function catalogLabels(): array
    {
        $emojis = $this->itemEmojis();
        $defaults = [
            'apple' => ['name_ru' => 'Яблоко', 'unit' => 'шт', 'emoji' => '🍎'],
            'pear' => ['name_ru' => 'Груша', 'unit' => 'шт', 'emoji' => '🍐'],
            'banana' => ['name_ru' => 'Банан', 'unit' => 'шт', 'emoji' => '🍌'],
            'mandarin' => ['name_ru' => 'Мандарин', 'unit' => 'шт', 'emoji' => '🍊'],
            'peach' => ['name_ru' => 'Персик', 'unit' => 'шт', 'emoji' => '🍑'],
            'grape' => ['name_ru' => 'Виноград', 'unit' => 'шт', 'emoji' => '🍇'],
            'water_1l' => ['name_ru' => 'Вода', 'unit' => 'л', 'emoji' => '💧'],
            'pot_1l' => ['name_ru' => 'Горшок 1 л', 'unit' => 'шт', 'emoji' => '🫙'],
            'clay_kg' => ['name_ru' => 'Глина', 'unit' => 'кг', 'emoji' => '🧱'],
            'log' => ['name_ru' => 'Бревно', 'unit' => 'шт', 'emoji' => '🪵'],
            'bark' => ['name_ru' => 'Кора', 'unit' => 'шт', 'emoji' => '🟫'],
            'branch' => ['name_ru' => 'Ветка', 'unit' => 'шт', 'emoji' => '🌿'],
            'stone' => ['name_ru' => 'Камень', 'unit' => 'шт', 'emoji' => '🪨'],
            'wheat' => ['name_ru' => 'Пшеница', 'unit' => 'шт', 'emoji' => '🌾'],
            'cotton_cloth' => ['name_ru' => 'Ткань', 'unit' => 'шт', 'emoji' => '🧵'],
            'wool' => ['name_ru' => 'Шерсть', 'unit' => 'шт', 'emoji' => '🧶'],
            'milk_1l' => ['name_ru' => 'Молоко', 'unit' => 'л', 'emoji' => '🥛'],
            'egg' => ['name_ru' => 'Яйцо', 'unit' => 'шт', 'emoji' => '🥚'],
            'feather' => ['name_ru' => 'Перо', 'unit' => 'шт', 'emoji' => '🪶'],
            'paper' => ['name_ru' => 'Бумага', 'unit' => 'шт', 'emoji' => '📄'],
            'crate_4x4' => ['name_ru' => 'Ящик 4×4', 'unit' => 'шт', 'emoji' => '📦'],
            'axe_stone' => ['name_ru' => 'Каменный топор', 'unit' => 'шт', 'emoji' => '🪓'],
            'flatbread' => ['name_ru' => 'Лепёшка', 'unit' => 'шт', 'emoji' => '🫓'],
            'cheese' => ['name_ru' => 'Сыр', 'unit' => 'шт', 'emoji' => '🧀'],
            'building_project' => ['name_ru' => 'Проект', 'unit' => 'шт', 'emoji' => '📐'],
            'shirt' => ['name_ru' => 'Рубаха', 'unit' => 'шт', 'emoji' => '👕'],
        ];

        try {
            $stmt = $this->pdo->query(
                'SELECT sku_key, name_ru, unit FROM ref_catalog WHERE is_active = 1'
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable) {
            return $defaults;
        }

        foreach ($rows as $row) {
            $sku = (string) $row['sku_key'];
            $defaults[$sku] = [
                'name_ru' => (string) $row['name_ru'],
                'unit' => (string) ($row['unit'] ?? ''),
                'emoji' => $emojis[$sku] ?? '',
            ];
        }

        return $defaults;
    }

    /**
     * @param array<string, float> $stock
     * @param array<string, float> $kcalMap
     * @param list<string> $fruitPair
     * @return array{0: float, 1: float, 2: array<string, int>}
     */
    private function rationPerson(
        array &$stock,
        float $kcalNeed,
        float $waterNeed,
        array $kcalMap,
        array $fruitPair,
        int $personIndex
    ): array {
        $waterGot = 0.0;
        if (($stock['water_1l'] ?? 0.0) >= $waterNeed) {
            $stock['water_1l'] -= $waterNeed;
            $waterGot = $waterNeed;
        } elseif (($stock['water_1l'] ?? 0.0) > 0) {
            $waterGot = (float) $stock['water_1l'];
            $stock['water_1l'] = 0.0;
        }

        $fruit = $this->takeKcalWithPreference($stock, $kcalNeed, $kcalMap, $fruitPair, $personIndex);

        return [$fruit['kcal'], $waterGot, $fruit['pieces']];
    }

    /**
     * Житель сам выбирает фрукт (предпочтение по индексу), остаток — с другого вида.
     *
     * @param array<string, float> $stock
     * @param array<string, float> $kcalMap
     * @param list<string> $fruitPair
     * @return array{kcal: float, pieces: array<string, int>}
     */
    private function takeKcalWithPreference(
        array &$stock,
        float $kcalNeed,
        array $kcalMap,
        array $fruitPair,
        int $personIndex
    ): array {
        if ($kcalNeed <= 0) {
            return ['kcal' => 0.0, 'pieces' => []];
        }

        $order = $fruitPair;
        if (count($order) >= 2) {
            $start = $personIndex % count($order);
            $order = array_merge(array_slice($order, $start), array_slice($order, 0, $start));
        }

        $pieces = [];
        $got = 0.0;

        foreach ($order as $sku) {
            if ($got + 0.001 >= $kcalNeed) {
                break;
            }
            $unitKcal = $kcalMap[$sku] ?? 0.0;
            if ($unitKcal <= 0) {
                continue;
            }
            while ($got + 0.001 < $kcalNeed && ($stock[$sku] ?? 0.0) >= 1.0) {
                $stock[$sku] -= 1.0;
                $got += $unitKcal;
                $pieces[$sku] = ($pieces[$sku] ?? 0) + 1;
            }
        }

        if ($got + 0.001 < $kcalNeed) {
            $extra = $this->takeKcalFromStockGreedy($stock, $kcalNeed - $got, $kcalMap);
            $got += $extra['kcal'];
            foreach ($extra['pieces'] as $sku => $qty) {
                $pieces[$sku] = ($pieces[$sku] ?? 0) + $qty;
            }
        }

        return ['kcal' => $got, 'pieces' => $pieces];
    }

    /**
     * @param array<string, float> $stock
     * @param array<string, float> $kcalMap
     * @param list<string> $fruitPair
     * @return array{kcal: float, pieces: array<string, int>}
     */
    private function takeKcalFromStock(
        array &$stock,
        float $kcalNeed,
        array $kcalMap,
        array $fruitPair = []
    ): array {
        if ($kcalNeed <= 0) {
            return ['kcal' => 0.0, 'pieces' => []];
        }

        if ($fruitPair === []) {
            return $this->takeKcalFromStockGreedy($stock, $kcalNeed, $kcalMap);
        }

        $pieces = [];
        $got = 0.0;
        $perSkuTarget = $kcalNeed / count($fruitPair);

        foreach ($fruitPair as $sku) {
            $unitKcal = $kcalMap[$sku] ?? 0.0;
            if ($unitKcal <= 0) {
                continue;
            }
            $skuGot = 0.0;
            while ($skuGot + 0.001 < $perSkuTarget && ($stock[$sku] ?? 0.0) >= 1.0) {
                $stock[$sku] -= 1.0;
                $skuGot += $unitKcal;
                $pieces[$sku] = ($pieces[$sku] ?? 0) + 1;
            }
            $got += $skuGot;
        }

        if ($got + 0.001 < $kcalNeed) {
            $extra = $this->takeKcalFromStockGreedy($stock, $kcalNeed - $got, $kcalMap);
            $got += $extra['kcal'];
            foreach ($extra['pieces'] as $sku => $qty) {
                $pieces[$sku] = ($pieces[$sku] ?? 0) + $qty;
            }
        }

        return ['kcal' => $got, 'pieces' => $pieces];
    }

    /**
     * @param array<string, float> $stock
     * @param array<string, float> $kcalMap
     * @return array{kcal: float, pieces: array<string, int>}
     */
    private function takeKcalFromStockGreedy(array &$stock, float $kcalNeed, array $kcalMap): array
    {
        $skus = [];
        foreach ($stock as $sku => $qty) {
            if ($qty <= 0 || $sku === 'water_1l' || $sku === 'pot_1l') {
                continue;
            }
            if (($kcalMap[$sku] ?? 0) <= 0) {
                continue;
            }
            $skus[] = $sku;
        }

        usort($skus, static function (string $a, string $b) use ($kcalMap): int {
            return (int) (($kcalMap[$b] ?? 0) <=> ($kcalMap[$a] ?? 0));
        });

        $got = 0.0;
        $pieces = [];
        foreach ($skus as $sku) {
            if ($got >= $kcalNeed) {
                break;
            }
            $unitKcal = $kcalMap[$sku] ?? 0.0;
            while ($got < $kcalNeed && ($stock[$sku] ?? 0.0) >= 1.0) {
                $stock[$sku] -= 1.0;
                $got += $unitKcal;
                $pieces[$sku] = ($pieces[$sku] ?? 0) + 1;
            }
        }

        return ['kcal' => $got, 'pieces' => $pieces];
    }

    /** @return list<string> один sku фрукта деревни */
    private function fruitPairForProvince(int $provinceId): array
    {
        $sku = self::VILLAGE_FRUITS[$provinceId] ?? 'apple';
        return [$sku];
    }

    /** @return array<string, float> */
    private function fruitKcalMap(): array
    {
        $defaults = [
            'apple' => 100.0,
            'pear' => 100.0,
            'banana' => 100.0,
            'peach' => 100.0,
            'mandarin' => 50.0,
            'grape' => 20.0,
        ];

        try {
            $stmt = $this->pdo->query(
                "SELECT sku_key, kcal FROM ref_catalog
                 WHERE kcal IS NOT NULL AND kcal > 0 AND is_active = 1"
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable) {
            return $defaults;
        }

        foreach ($rows as $row) {
            $defaults[(string) $row['sku_key']] = (float) $row['kcal'];
        }

        return $defaults;
    }

    /** @param array<string, mixed> $tick */
    private function formatTickSummaryMessage(
        int $day,
        int $month,
        int $year,
        array $tick
    ): string {
        $mode = !empty($tick['is_rest_day']) ? 'выходной' : 'рабочий';
        return sprintf(
            'День %d.%d.%d (%s). Сытость по миру: %d / %d / %d (из %d). Ср. энергия %.1f.',
            $day,
            $month,
            $year,
            $mode,
            $tick['full'],
            $tick['partial'],
            $tick['hungry'],
            $tick['alive'],
            $tick['avg_energy']
        );
    }

    /**
     * @param array<string, mixed> $tick
     * @param array<string, array{name_ru: string, unit: string}> $labels
     */
    private function formatSettlementTickMessage(array $tick, array $labels): string
    {
        $isRest = !empty($tick['is_rest_day']);
        $workLines = $tick['work_lines'] ?? [];
        $workBlock = $workLines === []
            ? ($isRest ? 'выходной' : '—')
            : implode('; ', $workLines);
        $gatherFruit = $this->formatFruitPieces($tick['gather_pieces'] ?? [], $labels, '+');
        $rationFruit = $this->formatFruitPieces($tick['ration_pieces'] ?? [], $labels, '−');
        // В летописи — только еда/вода; полный склад смотреть в мониторе.
        $stockFood = $this->formatFruitPieces(
            $this->foodPiecesOnly($tick['stock_pieces'] ?? [], $labels),
            $labels
        );
        $waterEmoji = $labels['water_1l']['emoji'] ?? '💧';

        if ($isRest) {
            return sprintf(
                "Труд: %s.\n"
                . "Рацион: %s, −%s %.0f л.\n"
                . "Склад (еда): %s, %s %.0f л.\n"
                . 'Сытость: %d / %d / %d (из %d). Ср. энергия %.1f.',
                $workBlock,
                $rationFruit,
                $waterEmoji,
                $tick['ration_water'],
                $stockFood,
                $waterEmoji,
                $tick['stock_water'] ?? 0.0,
                $tick['full'],
                $tick['partial'],
                $tick['hungry'],
                $tick['alive'],
                $tick['avg_energy']
            );
        }

        return sprintf(
            "Сбор: %s.\n"
            . "Труд: %s.\n"
            . "Рацион: %s, −%s %.0f л.\n"
            . "Склад (еда): %s, %s %.0f л.\n"
            . 'Сытость: %d / %d / %d (из %d). Ср. энергия %.1f.',
            $gatherFruit,
            $workBlock,
            $rationFruit,
            $waterEmoji,
            $tick['ration_water'],
            $stockFood,
            $waterEmoji,
            $tick['stock_water'] ?? 0.0,
            $tick['full'],
            $tick['partial'],
            $tick['hungry'],
            $tick['alive'],
            $tick['avg_energy']
        );
    }

    /**
     * @param array<string, int> $pieces
     * @param array<string, array{name_ru: string, unit: string}> $labels
     * @return array<string, int>
     */
    private function foodPiecesOnly(array $pieces, array $labels): array
    {
        $foodSkus = [
            'apple', 'pear', 'banana', 'peach', 'mandarin', 'grape',
            'wheat', 'flatbread', 'milk_1l', 'cheese', 'egg',
        ];
        $out = [];
        foreach ($foodSkus as $sku) {
            if (($pieces[$sku] ?? 0) > 0) {
                $out[$sku] = (int) $pieces[$sku];
            }
        }
        return $out;
    }

    /**
     * @param array<string, int> $pieces
     * @return array<string, int>
     */
    private function piecesWithoutSku(array $pieces, string $sku): array
    {
        unset($pieces[$sku]);
        return $pieces;
    }

    private function insertChronicle(
        int $worldId,
        int $day,
        int $month,
        int $year,
        string $eventType,
        string $message,
        ?int $settlementId
    ): void {
        $this->pdo->prepare(
            'INSERT INTO chronicle (world_id, settlement_id, day, month, year, event_type, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            $eventType,
            $message,
        ]);
    }

    /**
     * @param array<string, int> $into
     * @param array<string, int> $add
     */
    private function mergePieceMaps(array &$into, array $add): void
    {
        foreach ($add as $sku => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $into[$sku] = ($into[$sku] ?? 0) + (int) $qty;
        }
    }

    /**
     * @param array<string, float> $inventory
     * @param array<string, float> $kcalMap
     * @return array<string, int>
     */
    private function fruitPieceTotals(array $inventory, array $kcalMap): array
    {
        $out = [];
        foreach ($inventory as $sku => $qty) {
            if ($qty <= 0 || ($kcalMap[$sku] ?? 0) <= 0) {
                continue;
            }
            $out[$sku] = ($out[$sku] ?? 0) + (int) round($qty);
        }
        return $out;
    }

    /**
     * @param array<string, float> $inventory
     * @return array<string, int>
     */
    private function inventoryPieceTotals(array $inventory): array
    {
        $out = [];
        foreach ($inventory as $sku => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $out[$sku] = ($out[$sku] ?? 0) + (int) round($qty);
        }
        return $out;
    }

    /**
     * @param array<string, int> $pieces
     * @param array<string, array{name_ru: string, unit: string, emoji: string}> $labels
     */
    private function formatFruitPieces(array $pieces, array $labels, string $sign = ''): string
    {
        if ($pieces === []) {
            return '—';
        }

        ksort($pieces);
        $parts = [];
        foreach ($pieces as $sku => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $name = $labels[$sku]['name_ru'] ?? $sku;
            $unit = $labels[$sku]['unit'] ?? 'шт';
            $emoji = $labels[$sku]['emoji'] ?? '';
            $prefix = $emoji !== '' ? $emoji . ' ' : '';
            $n = (int) $qty;
            $parts[] = $sign !== ''
                ? "{$prefix}{$name} {$sign}{$n} {$unit}"
                : "{$prefix}{$name} {$n} {$unit}";
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    /** @return array{kcal_day: float, water_day: float} */
    private function loadClickNeeds(): array
    {
        $defaults = ['kcal_day' => 300.0, 'water_day' => 1.0];
        try {
            $stmt = $this->pdo->query(
                "SELECT need_key, amount FROM ref_click_needs
                 WHERE need_key IN ('kcal_day','water_day') AND is_active = 1"
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable) {
            return $defaults;
        }

        foreach ($rows as $row) {
            $key = (string) $row['need_key'];
            if (isset($defaults[$key]) && $row['amount'] !== null) {
                $defaults[$key] = (float) $row['amount'];
            }
        }
        return $defaults;
    }

    /** @return array{0: int, 1: int} col, row на сетке 8×5 */
    private function provinceGridPos(int $provinceId): array
    {
        $index = max(0, $provinceId - 1);
        return [$index % 8, intdiv($index, 8)];
    }

    private function seedSettlementStock(
        int $settlementId,
        int $perVillage,
        float $kcalPerDay,
        float $waterPerDay,
        int $stockDays,
        float $buffer
    ): void {
        $ins = $this->pdo->prepare(
            'INSERT INTO settlement_stock (settlement_id, sku_key, qty) VALUES (?, ?, ?)'
        );
        $waterQty = round($perVillage * $waterPerDay * $stockDays * $buffer, 3);
        $potQty = $perVillage * 4;
        $ins->execute([$settlementId, 'water_1l', $waterQty]);
        $ins->execute([$settlementId, 'pot_1l', $potQty]);
    }

    private function insertWorldCreatedChronicle(
        int $worldId,
        int $villages,
        int $perVillage,
        int $startPop,
        int $stockDays,
        float $buffer,
        int $cap,
        int $seed,
        bool $reset = false
    ): void {
        $this->insertChronicle(
            $worldId,
            1,
            1,
            1,
            $reset ? 'world_reset' : 'world_created',
            sprintf(
                'Мир %s: Макленд, %d жителей, склад на %d сут., ФОТ %d, НДС %.0f%% (seed %d)',
                $reset ? 'сброшен' : 'создан',
                $startPop,
                $stockDays,
                (int) ($this->appConfig['wage_month'] ?? 100),
                ((float) ($this->appConfig['vat_rate'] ?? 0.07)) * 100,
                $seed
            ),
            null
        );
    }

    private function clearWorldEconomy(int $worldId): void
    {
        $this->pdo->prepare('DELETE FROM ledger_entries WHERE world_id = ?')->execute([$worldId]);
        $this->pdo->prepare('DELETE FROM wallets WHERE world_id = ?')->execute([$worldId]);
        $this->pdo->prepare('DELETE FROM companies WHERE world_id = ?')->execute([$worldId]);
    }

    /**
     * Щелчок экономики: казна, пособия, ЧБ, лицензии, кредиты 50, гроссбух 4, абонентка 2.
     * Летопись — короткие логи.
     */
    private function bootstrapClickEconomy(int $worldId, int $settlementId): void
    {
        $benefit = (float) ($this->appConfig['start_benefit'] ?? 100);
        $credit = (float) ($this->appConfig['company_start_credit'] ?? 50);
        $license = (float) ($this->appConfig['company_license_fee'] ?? 4);
        $grossbook = (float) ($this->appConfig['company_grossbook_fee'] ?? 4);
        $fee = (float) ($this->appConfig['bank_account_fee_month'] ?? 2);
        $rate = (float) ($this->appConfig['bank_credit_rate_year'] ?? 0.15);
        $issuePerPerson = (float) ($this->appConfig['money_issue_per_person'] ?? 200);
        $bankCapital = (float) ($this->appConfig['bank_start_capital'] ?? 1500);

        $day = 1;
        $month = 1;
        $year = 1;

        $peopleStmt = $this->pdo->prepare(
            'SELECT id, name FROM people WHERE settlement_id = ? AND status = \'alive\' ORDER BY id'
        );
        $peopleStmt->execute([$settlementId]);
        $people = $peopleStmt->fetchAll();
        $peopleN = count($people);

        // Эмиссия: государство вводит дензнаки в казну (без оплаты). Привязка к людям.
        if ($issuePerPerson > 0) {
            $treasuryStart = $issuePerPerson * $peopleN;
        } else {
            $treasuryStart = (float) ($this->appConfig['start_treasury_balance'] ?? 10000);
        }

        $treasuryId = $this->createWallet($worldId, $settlementId, 'treasury', $settlementId, $treasuryStart);
        $this->logMoney(
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            'treasury_seed',
            $treasuryStart,
            null,
            $treasuryId,
            null,
            sprintf(
                'эмиссия %.0f × %d чел. = %.0f',
                $issuePerPerson > 0 ? $issuePerPerson : $treasuryStart,
                $peopleN,
                $treasuryStart
            )
        );
        $this->insertChronicle(
            $worldId,
            $day,
            $month,
            $year,
            'treasury_seed',
            sprintf(
                'Эмиссия в казну: %.0f × %d жителей = %.0f (без оплаты)',
                $issuePerPerson > 0 ? $issuePerPerson : $treasuryStart,
                $peopleN,
                $treasuryStart
            ),
            $settlementId
        );
        foreach ($people as $person) {
            $pid = (int) $person['id'];
            $pw = $this->createWallet($worldId, $settlementId, 'person', $pid, 0);
            $this->transfer(
                $worldId,
                $settlementId,
                $day,
                $month,
                $year,
                'benefit',
                $benefit,
                $treasuryId,
                $pw,
                null,
                sprintf('пособие person#%d +%.2f', $pid, $benefit)
            );
        }
        $this->insertChronicle(
            $worldId,
            $day,
            $month,
            $year,
            'benefits',
            sprintf(
                'Пособия жителям: %.0f × %d чел. = %.0f (из казны)',
                $benefit,
                count($people),
                $benefit * count($people)
            ),
            $settlementId
        );

        $bankMeta = null;
        $bankWalletId = 0;
        $bankCompanyId = 0;
        $firmMetas = [];

        foreach (self::START_COMPANIES as $def) {
            $companyId = $this->insertCompany(
                $worldId,
                $settlementId,
                $def['code'],
                $def['title'],
                $def['kind'],
                $def['workers'],
                $def['kind'] === 'bank' ? 0.0 : $credit,
                $def['kind'] === 'bank' ? 0.0 : $rate,
                $day,
                $month,
                $year,
                $def['code']
            );
            $this->copyCompanyLinesFromTemplate($companyId, $def['code']);
            $walletId = $this->createWallet($worldId, $settlementId, 'company', $companyId, 0);
            $meta = [
                'id' => $companyId,
                'code' => $def['code'],
                'kind' => $def['kind'],
                'wallet_id' => $walletId,
            ];
            if ($def['kind'] === 'bank') {
                $bankMeta = $meta;
                $bankWalletId = $walletId;
                $bankCompanyId = $companyId;
            } else {
                $firmMetas[] = $meta;
            }
        }

        if ($bankMeta === null) {
            throw new \RuntimeException('ЧБ не создан');
        }

        $this->transfer(
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            'bank_capital',
            $bankCapital,
            $treasuryId,
            $bankWalletId,
            $bankCompanyId,
            sprintf('ЧБ капитал %.2f', $bankCapital)
        );
        $this->insertChronicle(
            $worldId,
            $day,
            $month,
            $year,
            'bank_founded',
            sprintf('Основан Частный Банк (ЧБ), капитал %.0f из казны', $bankCapital),
            $settlementId
        );

        $this->transfer(
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            'license',
            $license,
            $bankWalletId,
            $treasuryId,
            $bankCompanyId,
            sprintf('%s лицензия %.2f', $bankMeta['code'], $license)
        );
        $this->transfer(
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            'grossbook',
            $grossbook,
            $bankWalletId,
            $treasuryId,
            $bankCompanyId,
            sprintf('%s гроссбух %.2f', $bankMeta['code'], $grossbook)
        );
        $this->pdo->prepare('UPDATE companies SET has_grossbook = 1 WHERE id = ?')
            ->execute([$bankCompanyId]);
        $this->insertChronicle(
            $worldId,
            $day,
            $month,
            $year,
            'license',
            sprintf(
                'ЧБ оформил лицензию (%.0f) и гроссбух (%.0f) — оплата в казну',
                $license,
                $grossbook
            ),
            $settlementId
        );

        foreach ($firmMetas as $firm) {
            $this->transfer(
                $worldId,
                $settlementId,
                $day,
                $month,
                $year,
                'credit',
                $credit,
                $bankWalletId,
                $firm['wallet_id'],
                $firm['id'],
                sprintf('%s кредит %.2f @ %.0f%%', $firm['code'], $credit, $rate * 100)
            );
            $this->transfer(
                $worldId,
                $settlementId,
                $day,
                $month,
                $year,
                'license',
                $license,
                $firm['wallet_id'],
                $treasuryId,
                $firm['id'],
                sprintf('%s лицензия %.2f', $firm['code'], $license)
            );
            $this->transfer(
                $worldId,
                $settlementId,
                $day,
                $month,
                $year,
                'grossbook',
                $grossbook,
                $firm['wallet_id'],
                $treasuryId,
                $firm['id'],
                sprintf('%s гроссбух %.2f', $firm['code'], $grossbook)
            );
            $this->pdo->prepare('UPDATE companies SET has_grossbook = 1 WHERE id = ?')
                ->execute([$firm['id']]);
            $this->transfer(
                $worldId,
                $settlementId,
                $day,
                $month,
                $year,
                'account_fee',
                $fee,
                $firm['wallet_id'],
                $bankWalletId,
                $firm['id'],
                sprintf('%s р/с абонентка %.2f', $firm['code'], $fee)
            );
            $this->insertChronicle(
                $worldId,
                $day,
                $month,
                $year,
                'company_start',
                sprintf(
                    '%s стартовала: кредит ЧБ %.0f @ %.0f%%; лицензия %.0f и гроссбух %.0f → казна; '
                    . 'абонентка р/с %.0f → ЧБ; в кассе %.0f',
                    $firm['code'],
                    $credit,
                    $rate * 100,
                    $license,
                    $grossbook,
                    $fee,
                    $credit - $license - $grossbook - $fee
                ),
                $settlementId
            );
        }

        $this->insertChronicle(
            $worldId,
            $day,
            $month,
            $year,
            'work_ready',
            sprintf('Старт работ: %d фирм готовы к смене', count(self::START_COMPANIES)),
            $settlementId
        );
    }

    private function chargeMonthlyAccountFees(int $worldId, int $day, int $month, int $year): void
    {
        $fee = (float) ($this->appConfig['bank_account_fee_month'] ?? 2);
        if ($fee <= 0) {
            return;
        }

        $bankStmt = $this->pdo->prepare(
            'SELECT c.id, w.id AS wallet_id
             FROM companies c
             JOIN wallets w ON w.world_id = c.world_id AND w.owner_type = \'company\' AND w.owner_id = c.id
             WHERE c.world_id = ? AND c.kind = \'bank\' LIMIT 1'
        );
        $bankStmt->execute([$worldId]);
        $bank = $bankStmt->fetch();
        if (!$bank) {
            return;
        }
        $bankWalletId = (int) $bank['wallet_id'];

        $firms = $this->pdo->prepare(
            'SELECT c.id, c.code, c.settlement_id, w.id AS wallet_id, w.balance
             FROM companies c
             JOIN wallets w ON w.world_id = c.world_id AND w.owner_type = \'company\' AND w.owner_id = c.id
             WHERE c.world_id = ? AND c.kind = \'firm\''
        );
        $firms->execute([$worldId]);
        $charged = 0;
        $total = 0.0;
        foreach ($firms->fetchAll() as $firm) {
            $from = (int) $firm['wallet_id'];
            $sid = (int) $firm['settlement_id'];
            $bal = (float) $firm['balance'];
            if ($bal < $fee) {
                $this->insertChronicle(
                    $worldId,
                    $day,
                    $month,
                    $year,
                    'account_fee_fail',
                    sprintf(
                        '%s: абонентка р/с %.0f не списана — на счёте только %.2f',
                        $firm['code'],
                        $fee,
                        $bal
                    ),
                    $sid
                );
                continue;
            }
            $this->transfer(
                $worldId,
                $sid,
                $day,
                $month,
                $year,
                'account_fee',
                $fee,
                $from,
                $bankWalletId,
                (int) $firm['id'],
                sprintf('%s р/с абонентка %.2f', $firm['code'], $fee)
            );
            $charged++;
            $total += $fee;
        }
        if ($charged > 0) {
            $this->insertChronicle(
                $worldId,
                $day,
                $month,
                $year,
                'account_fees',
                sprintf(
                    '1-е число: абонентка р/с у %d фирм, всего %.0f → ЧБ',
                    $charged,
                    $total
                ),
                null
            );
        }
    }

    private function insertCompany(
        int $worldId,
        int $settlementId,
        string $code,
        string $title,
        string $kind,
        int $workers,
        float $creditPrincipal,
        float $creditRate,
        int $day,
        int $month,
        int $year,
        ?string $templateCode = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO companies
               (world_id, settlement_id, code, title, kind, template_code, workers, credit_principal, credit_rate,
                has_grossbook, created_day, created_month, created_year)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([
            $worldId,
            $settlementId,
            $code,
            $title,
            $kind,
            $templateCode ?? $code,
            $workers,
            $creditPrincipal,
            $creditRate,
            $day,
            $month,
            $year,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Копия норм шаблона → план выпуска компании поселения. */
    private function copyCompanyLinesFromTemplate(int $companyId, string $templateCode): void
    {
        $src = $this->pdo->prepare(
            'SELECT sku_key, qty_day, sort_order FROM ref_firm_product
             WHERE firm_code = ? ORDER BY sort_order ASC, id ASC'
        );
        $src->execute([$templateCode]);
        $rows = $src->fetchAll();
        if (!$rows) {
            return;
        }
        $ins = $this->pdo->prepare(
            'INSERT INTO company_lines (company_id, sku_key, qty_day, sort_order)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty_day = VALUES(qty_day), sort_order = VALUES(sort_order)'
        );
        foreach ($rows as $r) {
            $ins->execute([
                $companyId,
                $r['sku_key'],
                $r['qty_day'],
                (int) $r['sort_order'],
            ]);
        }
    }

    private function createWallet(
        int $worldId,
        int $settlementId,
        string $ownerType,
        int $ownerId,
        float $balance
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wallets (world_id, settlement_id, owner_type, owner_id, balance)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$worldId, $settlementId, $ownerType, $ownerId, $balance]);
        return (int) $this->pdo->lastInsertId();
    }

    private function transfer(
        int $worldId,
        int $settlementId,
        int $day,
        int $month,
        int $year,
        string $kind,
        float $amount,
        int $fromWalletId,
        int $toWalletId,
        ?int $companyId,
        string $note
    ): void {
        if ($amount <= 0) {
            return;
        }
        $this->pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?')
            ->execute([$amount, $fromWalletId]);
        $this->pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?')
            ->execute([$amount, $toWalletId]);
        $this->logMoney(
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            $kind,
            $amount,
            $fromWalletId,
            $toWalletId,
            $companyId,
            $note
        );
    }

    private function logMoney(
        int $worldId,
        ?int $settlementId,
        int $day,
        int $month,
        int $year,
        string $kind,
        float $amount,
        ?int $fromWalletId,
        ?int $toWalletId,
        ?int $companyId,
        string $note
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ledger_entries
               (world_id, settlement_id, day, month, year, kind, amount,
                from_wallet_id, to_wallet_id, company_id, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $worldId,
            $settlementId,
            $day,
            $month,
            $year,
            $kind,
            $amount,
            $fromWalletId,
            $toWalletId,
            $companyId,
            $note,
        ]);
    }
}
