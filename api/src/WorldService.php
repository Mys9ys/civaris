<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

final class WorldService
{
    /** Малая карта: стартовые провинции (канон /map). */
    private const SMALL_START_PROVINCES = [2, 7, 21, 34, 39];

    /** Две фруктовые культуры на деревню (специализация). */
    private const VILLAGE_FRUIT_PAIRS = [
        ['apple', 'pear'],
        ['banana', 'mandarin'],
        ['peach', 'grape'],
        ['apple', 'mandarin'],
        ['pear', 'banana'],
    ];

    private const PERSON_NAMES = [
        'Артём', 'Борис', 'Вера', 'Галина', 'Дарья', 'Егор', 'Жанна', 'Илья',
        'Кира', 'Лев', 'Мария', 'Никита', 'Ольга', 'Павел', 'Рита', 'Сергей',
        'Татьяна', 'Ульяна', 'Фёдор', 'Юлия',
    ];

    /** @var array<string, string> */
    private const JOB_LABELS = [
        'gatherer' => 'Сборщик',
        'chief' => 'Вождь',
        'banker' => 'Банкир',
        'shopkeeper' => 'Продавец',
        'water_carrier' => 'Сборщик воды',
        'clay_digger' => 'Добытчик глины',
        'woodcutter' => 'Дровосек',
        'builder' => 'Строитель',
        'potter' => 'Гончар',
        'carpenter' => 'Плотник',
        'carrier' => 'Транспорт',
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
        $world['week'] = intdiv($day - 1, $cal['days_per_week']) + 1;
        $world['day_in_week'] = (($day - 1) % $cal['days_per_week']) + 1;
        $world['calendar'] = $cal;
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
     * Срез A: малая карта — 5 деревень × 20 человек, склады по нормам щелчка + запас.
     */
    public function create(int $userId, string $name, ?int $mapSeed = null): array
    {
        $cap = (int) $this->appConfig['free_population_cap'];
        $villages = (int) ($this->appConfig['start_villages'] ?? 5);
        $perVillage = (int) ($this->appConfig['people_per_village'] ?? 20);
        $startPop = $villages * $perVillage;
        $stockDays = (int) ($this->appConfig['start_stock_days'] ?? 7);
        $buffer = (float) ($this->appConfig['start_stock_buffer'] ?? 1.2);
        $mapSize = (string) ($this->appConfig['default_map_size'] ?? 'small');
        $seed = $mapSeed ?? random_int(1, 99999);

        if ($mapSize !== 'small' || $villages !== count(self::SMALL_START_PROVINCES)) {
            throw new \InvalidArgumentException('Срез A поддерживает только малую карту (5 стартовых провинций)');
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
                $insSettlement->execute([
                    $worldId,
                    'Деревня ' . ($i + 1),
                    $col * 100,
                    $row * 100,
                    $perVillage,
                    $provinceId,
                    $perVillage,
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

        $perVillage = (int) ($this->appConfig['people_per_village'] ?? 20);
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
                $this->seedSettlementStock(
                    $settlementId,
                    $perVillage,
                    $kcalPerDay,
                    $waterPerDay,
                    $stockDays,
                    $buffer
                );
                $this->assignVillageJobs($settlementId, (int) $row['province_id']);
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

        $this->pdo->beginTransaction();
        try {
            $tick = $this->runDayTick(
                $worldId,
                $kcalPerDay,
                $waterPerDay,
                $penaltyPartial,
                $penaltyHungry,
                $kcalMap,
                $mapSeed
            );

            $this->pdo->prepare(
                'UPDATE worlds SET day = ?, month = ?, year = ? WHERE id = ? AND user_id = ?'
            )->execute([$day, $month, $year, $worldId, $userId]);

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
     *   ration_kcal: float,
     *   ration_water: float,
     *   ration_pieces: array<string, int>,
     *   stock_pieces: array<string, int>,
     *   stock_water: float,
     *   settlements: list<array<string, mixed>>,
     *   avg_energy: float
     * }
     */
    private function runDayTick(
        int $worldId,
        float $kcalNeed,
        float $waterNeed,
        int $penaltyPartial,
        int $penaltyHungry,
        array $kcalMap,
        ?int $mapSeed
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
            'ration_kcal' => 0.0,
            'ration_water' => 0.0,
            'ration_pieces' => [],
            'stock_pieces' => [],
            'stock_water' => 0.0,
            'energy_sum' => 0,
            'settlements' => [],
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
                'gather_water' => 0.0,
                'gather_pieces' => [],
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

            $gathered = $this->harvestFromUgodiyaToStock(
                $stock,
                $fruitPair,
                $alive,
                $kcalNeed,
                $waterNeed,
                $kcalMap,
                $mods['kcal'],
                $mods['water']
            );
            $totals['gather_kcal'] += $gathered['kcal'];
            $totals['gather_water'] += $gathered['water'];
            $this->mergePieceMaps($totals['gather_pieces'], $gathered['pieces']);
            $settlementTick['gather_water'] = $gathered['water'];
            $settlementTick['gather_pieces'] = $gathered['pieces'];

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
            $settlementTick['stock_pieces'] = $this->fruitPieceTotals($stock, $kcalMap);
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

    /**
     * Сбор: 3 сборщика на культуру → урожай на residents_per_crop жителей + surplus%.
     *
     * @param array<string, float> $stock
     * @param list<string> $fruitPair
     * @return array{kcal: float, water: float, pieces: array<string, int>}
     */
    private function harvestFromUgodiyaToStock(
        array &$stock,
        array $fruitPair,
        int $alive,
        float $kcalPerDay,
        float $waterPerDay,
        array $kcalMap,
        float $kcalMod,
        float $waterMod
    ): array {
        if ($alive <= 0) {
            return ['kcal' => 0.0, 'water' => 0.0, 'pieces' => []];
        }

        $residentsPerCrop = (int) ($this->appConfig['residents_per_crop'] ?? 10);
        $cropSurplus = (float) ($this->appConfig['gather_crop_surplus_ratio'] ?? 1.25);
        $waterSurplus = (float) ($this->appConfig['gather_water_surplus_ratio'] ?? 1.25);

        $pieces = [];
        $movedKcal = 0.0;

        foreach ($fruitPair as $sku) {
            $kcalTarget = $residentsPerCrop * $kcalPerDay * $cropSurplus * $kcalMod;
            $unitKcal = $kcalMap[$sku] ?? 100.0;
            if ($unitKcal <= 0) {
                continue;
            }
            $qty = (int) ceil($kcalTarget / $unitKcal);
            $stock[$sku] = ($stock[$sku] ?? 0.0) + $qty;
            $pieces[$sku] = $qty;
            $movedKcal += $qty * $unitKcal;
        }

        $waterTarget = $alive * $waterPerDay * $waterSurplus * $waterMod;
        if ($waterTarget > 0) {
            $stock['water_1l'] = ($stock['water_1l'] ?? 0.0) + $waterTarget;
        }

        return ['kcal' => $movedKcal, 'water' => $waterTarget, 'pieces' => $pieces];
    }

    /**
     * @param list<string> $fruitPair
     * @return list<array{job_key: string, job_crop: ?string}>
     */
    private function villageJobSlots(array $fruitPair): array
    {
        $slots = [];
        $perCrop = (int) ($this->appConfig['gatherers_per_crop'] ?? 3);

        foreach ($fruitPair as $sku) {
            for ($i = 0; $i < $perCrop; $i++) {
                $slots[] = ['job_key' => 'gatherer', 'job_crop' => $sku];
            }
        }

        foreach (
            [
                ['job_key' => 'chief'],
                ['job_key' => 'banker'],
                ['job_key' => 'shopkeeper'],
                ['job_key' => 'water_carrier'],
                ['job_key' => 'water_carrier'],
                ['job_key' => 'clay_digger'],
                ['job_key' => 'woodcutter'],
                ['job_key' => 'woodcutter'],
                ['job_key' => 'builder'],
                ['job_key' => 'builder'],
                ['job_key' => 'potter'],
                ['job_key' => 'carpenter'],
                ['job_key' => 'carrier'],
                ['job_key' => 'carrier'],
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

    /** @return list<string> */
    private function fruitPairForProvince(int $provinceId): array
    {
        $index = array_search($provinceId, self::SMALL_START_PROVINCES, true);
        if ($index === false) {
            return self::VILLAGE_FRUIT_PAIRS[0];
        }
        return self::VILLAGE_FRUIT_PAIRS[$index];
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
        return sprintf(
            'День %d.%d.%d. Сытость по миру: %d / %d / %d (из %d). Ср. энергия %.1f.',
            $day,
            $month,
            $year,
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
        $gatherFruit = $this->formatFruitPieces($tick['gather_pieces'] ?? [], $labels, '+');
        $rationFruit = $this->formatFruitPieces($tick['ration_pieces'] ?? [], $labels, '−');
        $stockFruit = $this->formatFruitPieces($tick['stock_pieces'] ?? [], $labels);
        $waterEmoji = $labels['water_1l']['emoji'] ?? '💧';

        return sprintf(
            "Сбор: %s, +%s %.0f л → склад.\n"
            . "Рацион: %s, −%s %.0f л.\n"
            . "Склад: %s, %s %.0f л.\n"
            . 'Сытость: %d / %d / %d (из %d). Ср. энергия %.1f.',
            $gatherFruit,
            $waterEmoji,
            $tick['gather_water'],
            $rationFruit,
            $waterEmoji,
            $tick['ration_water'],
            $stockFruit,
            $waterEmoji,
            $tick['stock_water'] ?? 0.0,
            $tick['full'],
            $tick['partial'],
            $tick['hungry'],
            $tick['alive'],
            $tick['avg_energy']
        );
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
        $prefix = $reset ? 'Перезапуск (админ). ' : '';
        $cropSurplus = (float) ($this->appConfig['gather_crop_surplus_ratio'] ?? 1.25);
        $waterSurplus = (float) ($this->appConfig['gather_water_surplus_ratio'] ?? 1.25);
        $gatherers = (int) ($this->appConfig['gatherers_per_crop'] ?? 3);
        $residents = (int) ($this->appConfig['residents_per_crop'] ?? 10);
        $cal = $this->calendarLimits();
        $this->insertChronicle(
            $worldId,
            1,
            1,
            1,
            $reset ? 'world_reset' : 'world_created',
            sprintf(
                '%sЩелчок 1.1.1. Календарь: %d дн/нед × %d нед/мес × %d мес = %d дн/год. '
                . 'Малая карта: %d деревень × %d чел. (всего %d) на провинциях %s. '
                . 'Угодья: возобновляемые (без запасов). Склад (вода/тара): %d сут. × %.0f%%. '
                . 'Сбор: %d сборщика/культура → %d жителей + %.0f%%. Вода: %.0f%%. Free-cap: %d. Seed: %d.',
                $prefix,
                $cal['days_per_week'],
                $cal['weeks_per_month'],
                $cal['months_per_year'],
                $cal['days_per_month'] * $cal['months_per_year'],
                $villages,
                $perVillage,
                $startPop,
                implode('/', self::SMALL_START_PROVINCES),
                $stockDays,
                ($buffer - 1) * 100,
                $gatherers,
                $residents,
                ($cropSurplus - 1) * 100,
                ($waterSurplus - 1) * 100,
                $cap,
                $seed
            ),
            null
        );
    }
}
