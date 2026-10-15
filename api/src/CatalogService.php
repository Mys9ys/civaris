<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

/**
 * Каталог товаров фазы 0: паспорт SKU, рецепт, геометрия, фискальные константы.
 * Источник посева: api/data/catalog_seed.json (Sheet «Товары»).
 */
final class CatalogService
{
    private const SKU_COLUMNS = [
        'sku_key', 'name_ru', 'product_type', 'product_kind', 'producer_code',
        'unit', 'sell_channel', 'kind', 'is_tool', 'icon_key', 'icon_mark', 'is_shop',
        'labor_min', 'shift_pct', 'labor_workers', 'build_partitions', 'build_windows', 'build_doors',
        'pair_sku_key',
        'thermal_note', 'tool_name', 'transport_name',
        'shape', 'width_cm', 'length_cm', 'height_cm', 'density', 'vol_cyl_l', 'vol_box_l', 'weight_kg',
        'cost_amort', 'cost_transport', 'cost_tara', 'pack_qty', 'contents_qty', 'transport_trips_day',
        'cost', 'cost_seb', 'price_internal', 'price_shop', 'prod_month_90',
        'notes', 'sort_order', 'is_active',
    ];

    private const KINDS = ['raw', 'internal', 'good', 'pack', 'tool', 'both', 'service', 'portion', 'project', 'building'];

    public function __construct(private PDO $pdo)
    {
    }

    public function listSkus(?string $q = null, ?string $producer = null): array
    {
        $sql = 'SELECT * FROM ref_sku WHERE is_active = 1';
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= ' AND (name_ru LIKE ? OR sku_key LIKE ? OR product_type LIKE ? OR producer_code LIKE ? OR kind LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $like, $like];
        }
        if ($producer !== null && $producer !== '') {
            $sql .= ' AND producer_code = ?';
            $params[] = $producer;
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getSku(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ref_sku WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['ingredients'] = $this->listIngredients($id);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function listIngredients(int $skuId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ref_sku_ingredient WHERE sku_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$skuId]);
        return $stmt->fetchAll();
    }

    public function createSku(array $body): array
    {
        $data = $this->pickSku($body);
        if (($data['sku_key'] ?? '') === '' || ($data['name_ru'] ?? '') === '') {
            throw new \InvalidArgumentException('Нужны sku_key и name_ru');
        }
        if (!isset($data['unit']) || $data['unit'] === '') {
            $data['unit'] = 'шт';
        }
        $data = $this->normalizeKindAndPrices($data);
        if (!isset($data['shape']) || $data['shape'] === '') {
            $data['shape'] = 'none';
        }
        if (!isset($data['is_active'])) {
            $data['is_active'] = 1;
        }
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = 0;
        }
        if (!isset($data['is_tool'])) {
            $data['is_tool'] = 0;
        }

        $cols = array_keys($data);
        $ph = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO ref_sku (`%s`) VALUES (%s)',
            implode('`,`', $cols),
            implode(',', $ph)
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        $id = (int) $this->pdo->lastInsertId();
        $this->replaceIngredients($id, $body['ingredients'] ?? []);
        return $this->getSku($id) ?? [];
    }

    public function updateSku(int $id, array $body): array
    {
        if (!$this->getSku($id)) {
            throw new \InvalidArgumentException('SKU не найден');
        }
        $data = $this->pickSku($body);
        if ($data === []) {
            throw new \InvalidArgumentException('Нет данных');
        }
        $data = $this->normalizeKindAndPrices($data);
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = '`' . $c . '` = :' . $c;
        }
        $sql = 'UPDATE ref_sku SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if (array_key_exists('ingredients', $body)) {
            $this->replaceIngredients($id, $body['ingredients'] ?? []);
        }
        return $this->getSku($id) ?? [];
    }

    public function deleteSku(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ref_sku WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<array<string, mixed>> */
    public function listFiscal(?int $worldId = null): array
    {
        if ($worldId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM ref_fiscal
                 WHERE (scope_type = 'world' AND world_id = ?)
                    OR scope_type = 'global'
                 ORDER BY FIELD(scope_type, 'world', 'global'), id ASC"
            );
            $stmt->execute([$worldId]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM ref_fiscal ORDER BY scope_type ASC, id ASC')->fetchAll();
    }

    public function resolveFiscal(?int $worldId = null): array
    {
        if ($worldId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM ref_fiscal WHERE scope_type = 'world' AND world_id = ? LIMIT 1"
            );
            $stmt->execute([$worldId]);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
        $row = $this->pdo->query(
            "SELECT * FROM ref_fiscal WHERE scope_type = 'global' ORDER BY id ASC LIMIT 1"
        )->fetch();
        if (!$row) {
            return [
                'scope_type' => 'global',
                'world_id' => null,
                'title' => 'default',
                'vat_rate' => 0.07,
                'shop_margin_rate' => 0.10,
                'producer_margin_rate' => 0.20,
                'util_rate' => 0.90,
                'labor_min_hour' => 50,
                'labor_min_day' => 300,
                'labor_min_month' => 2700,
            ];
        }
        return $row;
    }

    public function upsertFiscal(array $body): array
    {
        $scope = (string) ($body['scope_type'] ?? 'global');
        if ($scope !== 'global' && $scope !== 'world') {
            throw new \InvalidArgumentException('scope_type: global|world');
        }
        $worldId = isset($body['world_id']) ? (int) $body['world_id'] : null;
        if ($scope === 'global') {
            $worldId = null;
        } elseif ($worldId === null || $worldId <= 0) {
            throw new \InvalidArgumentException('Для world нужен world_id');
        }

        $fields = [
            'title' => (string) ($body['title'] ?? 'default'),
            'vat_rate' => (float) ($body['vat_rate'] ?? 0.07),
            'shop_margin_rate' => (float) ($body['shop_margin_rate'] ?? 0.10),
            'producer_margin_rate' => (float) ($body['producer_margin_rate'] ?? 0.20),
            'util_rate' => (float) ($body['util_rate'] ?? 0.90),
            'labor_min_hour' => (float) ($body['labor_min_hour'] ?? 50),
            'labor_min_day' => (float) ($body['labor_min_day'] ?? 300),
            'labor_min_month' => (float) ($body['labor_min_month'] ?? 2700),
        ];

        $existing = null;
        if ($scope === 'global') {
            $existing = $this->pdo->query(
                "SELECT id FROM ref_fiscal WHERE scope_type = 'global' LIMIT 1"
            )->fetch();
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM ref_fiscal WHERE scope_type = 'world' AND world_id = ? LIMIT 1"
            );
            $stmt->execute([$worldId]);
            $existing = $stmt->fetch();
        }

        if ($existing) {
            $sql = 'UPDATE ref_fiscal SET title=:title, vat_rate=:vat_rate,
                    shop_margin_rate=:shop_margin_rate, producer_margin_rate=:producer_margin_rate,
                    util_rate=:util_rate, labor_min_hour=:labor_min_hour,
                    labor_min_day=:labor_min_day, labor_min_month=:labor_min_month
                    WHERE id=:id';
            $stmt = $this->pdo->prepare($sql);
            foreach ($fields as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
            $stmt->execute();
            $id = (int) $existing['id'];
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ref_fiscal
                 (scope_type, world_id, title, vat_rate, shop_margin_rate, producer_margin_rate,
                  util_rate, labor_min_hour, labor_min_day, labor_min_month)
                 VALUES (:scope_type, :world_id, :title, :vat_rate, :shop_margin_rate,
                         :producer_margin_rate, :util_rate, :labor_min_hour, :labor_min_day, :labor_min_month)'
            );
            $stmt->bindValue(':scope_type', $scope);
            $stmt->bindValue(':world_id', $worldId, $worldId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            foreach ($fields as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->execute();
            $id = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('SELECT * FROM ref_fiscal WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Посев из catalog_seed.json, если таблица пуста (или force).
     *
     * @return array{imported:int, skipped:bool}
     */
    public function seedFromJson(bool $force = false): array
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ref_sku')->fetchColumn();
        if ($count > 0 && !$force) {
            return ['imported' => 0, 'skipped' => true];
        }
        if ($force) {
            $this->pdo->exec('DELETE FROM ref_sku_ingredient');
            $this->pdo->exec('DELETE FROM ref_sku');
        }

        $path = dirname(__DIR__) . '/data/catalog_seed.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Нет файла api/data/catalog_seed.json');
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('catalog_seed.json повреждён');
        }

        $imported = 0;
        $nameToKey = [];
        foreach ($raw as $row) {
            if (!is_array($row) || ($row['name_ru'] ?? '') === '') {
                continue;
            }
            $nameToKey[(string) $row['name_ru']] = (string) ($row['sku_key'] ?? '');
        }

        foreach ($raw as $row) {
            if (!is_array($row) || ($row['name_ru'] ?? '') === '') {
                continue;
            }
            $ings = $row['ingredients'] ?? [];
            unset($row['ingredients']);
            if (is_array($ings)) {
                foreach ($ings as &$ing) {
                    if (!is_array($ing)) {
                        continue;
                    }
                    $n = (string) ($ing['name_ru'] ?? '');
                    if ($n !== '' && isset($nameToKey[$n]) && $nameToKey[$n] !== '') {
                        $ing['ingredient_sku_key'] = $nameToKey[$n];
                    }
                }
                unset($ing);
            }
            $row['ingredients'] = $ings;
            // normalize producer merge
            if (($row['producer_code'] ?? '') === 'ГлинДоб') {
                $row['producer_code'] = 'ГонПром';
            }
            if (($row['producer_code'] ?? '') === 'Дерево') {
                $row['producer_code'] = 'ЛесПром';
            }
            $this->createSku($row);
            $imported++;
        }

        $this->ensureDefaultFiscal();
        return ['imported' => $imported, 'skipped' => false];
    }

    /**
     * Аккуратный долив из catalog_seed.json: только пустые поля у существующих SKU.
     * Не трогает цены, рецепт, kind, иконки, density/pack_qty если уже заданы.
     * Опционально подставляет density из справочника, где пусто.
     *
     * @return array{updated:int, density_filled:int, missing_in_db:list<string>, touched:list<string>}
     */
    public function mergeFillFromSeed(bool $fillDensity = true): array
    {
        $path = dirname(__DIR__) . '/data/catalog_seed.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Нет файла api/data/catalog_seed.json');
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('catalog_seed.json повреждён');
        }

        /** Поля, которые можно долить только если в БД NULL/пусто. */
        $fillIfEmpty = [
            'width_cm', 'length_cm', 'height_cm', 'vol_cyl_l', 'vol_box_l', 'weight_kg',
            'shape', 'labor_min', 'thermal_note', 'tool_name', 'transport_name',
            'product_type', 'product_kind', 'unit',
        ];

        $find = $this->pdo->prepare('SELECT * FROM ref_sku WHERE sku_key = ? LIMIT 1');
        $updated = 0;
        $densityFilled = 0;
        $missing = [];
        $touched = [];

        foreach ($raw as $seed) {
            if (!is_array($seed) || ($seed['sku_key'] ?? '') === '') {
                continue;
            }
            $key = (string) $seed['sku_key'];
            $find->execute([$key]);
            $db = $find->fetch();
            if (!$db) {
                $missing[] = $key;
                continue;
            }

            $sets = [];
            $params = [];
            foreach ($fillIfEmpty as $col) {
                if (!array_key_exists($col, $seed)) {
                    continue;
                }
                $incoming = $seed[$col];
                if ($incoming === null || $incoming === '') {
                    continue;
                }
                $cur = $db[$col] ?? null;
                $empty = $cur === null || $cur === '';
                // shape 'none' считаем пустым, если в сиде есть реальная форма
                if ($col === 'shape') {
                    $empty = $empty || $cur === 'none';
                    if ($incoming === 'none') {
                        continue;
                    }
                }
                if (!$empty) {
                    continue;
                }
                if (in_array($col, [
                    'width_cm', 'length_cm', 'height_cm', 'vol_cyl_l', 'vol_box_l', 'weight_kg',
                    'labor_min',
                ], true)) {
                    $incoming = self::ceil2($incoming);
                    if ($incoming === null) {
                        continue;
                    }
                }
                $sets[] = '`' . $col . '` = :' . $col;
                $params[$col] = $incoming;
            }

            if ($fillDensity) {
                $curDens = $db['density'] ?? null;
                if (($curDens === null || $curDens === '') && !isset($params['density'])) {
                    $sug = $this->suggestDensity(
                        (string) ($db['name_ru'] ?? $seed['name_ru'] ?? ''),
                        (string) ($db['product_kind'] ?? $seed['product_kind'] ?? '')
                    );
                    if ($sug && isset($sug['density'])) {
                        $sets[] = '`density` = :density';
                        $params['density'] = self::ceil2($sug['density']);
                        $densityFilled++;
                    }
                }
            }

            if ($sets === []) {
                continue;
            }
            $params['id'] = (int) $db['id'];
            $sql = 'UPDATE ref_sku SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->execute();
            $updated++;
            $touched[] = $key . ' (' . implode(',', array_keys(array_diff_key($params, ['id' => 1]))) . ')';
        }

        return [
            'updated' => $updated,
            'density_filled' => $densityFilled,
            'missing_in_db' => $missing,
            'touched' => $touched,
        ];
    }

    /** Путь к справочнику плотности (г/см³). */
    private function densityRefPath(): string
    {
        return dirname(__DIR__) . '/data/density_ref.json';
    }

    /** @return list<array<string, mixed>> */
    public function listDensities(): array
    {
        $path = $this->densityRefPath();
        if (!is_file($path)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row) || ($row['key'] ?? '') === '' || ($row['name_ru'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'key' => (string) $row['key'],
                'name_ru' => (string) $row['name_ru'],
                'density' => isset($row['density']) ? (float) $row['density'] : null,
                'product_kinds' => array_values(array_filter(array_map('strval', $row['product_kinds'] ?? []))),
                'name_match' => array_values(array_filter(array_map('strval', $row['name_match'] ?? []))),
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Подсказать плотность по виду / названию SKU.
     *
     * @return array<string, mixed>|null
     */
    public function suggestDensity(?string $nameRu, ?string $productKind = null): ?array
    {
        $name = mb_strtolower(trim((string) $nameRu));
        $kind = trim((string) $productKind);
        foreach ($this->listDensities() as $row) {
            if ($kind !== '') {
                foreach ($row['product_kinds'] as $pk) {
                    if (mb_strtolower($pk) === mb_strtolower($kind)) {
                        return $row;
                    }
                }
            }
        }
        if ($name === '') {
            return null;
        }
        foreach ($this->listDensities() as $row) {
            foreach ($row['name_match'] as $m) {
                $m = mb_strtolower(trim($m));
                if ($m !== '' && mb_strpos($name, $m) !== false) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * Сохранить справочник целиком (админка).
     *
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    public function saveDensities(array $items): array
    {
        $clean = [];
        $seen = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            $name = trim((string) ($row['name_ru'] ?? ''));
            if ($key === '' || $name === '') {
                continue;
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Дубль ключа плотности: ' . $key);
            }
            $seen[$key] = true;
            $dens = $row['density'] ?? null;
            if ($dens === '' || $dens === null) {
                throw new \InvalidArgumentException('Нужна плотность у «' . $name . '»');
            }
            $clean[] = [
                'key' => $key,
                'name_ru' => $name,
                'density' => round((float) $dens, 4),
                'product_kinds' => array_values(array_filter(array_map(
                    static fn ($x) => trim((string) $x),
                    is_array($row['product_kinds'] ?? null) ? $row['product_kinds'] : []
                ))),
                'name_match' => array_values(array_filter(array_map(
                    static fn ($x) => trim((string) $x),
                    is_array($row['name_match'] ?? null) ? $row['name_match'] : []
                ))),
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        }
        $path = $this->densityRefPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('Не удалось записать density_ref.json');
        }
        return $this->listDensities();
    }

    /**
     * Канон щелчка: код → название / kind / штат (см. village-jobs + WorldService::START_COMPANIES).
     *
     * @return list<array{code:string,title:string,kind:string,workers:int}>
     */
    public static function firmCanon(): array
    {
        return [
            // Казна = кошелёк: на старте без штата (нечего делать).
            ['code' => 'Казна', 'title' => 'Казна поселения', 'kind' => 'treasury', 'workers' => 0],
            ['code' => 'ЧБ', 'title' => 'Частный банк', 'kind' => 'bank', 'workers' => 1],
            ['code' => 'ЯблПром', 'title' => 'Фруктовая компания', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'Водосбор', 'title' => 'Сбор и разлив воды', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'ГонПром', 'title' => 'Глина и гончарство', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'ЛесПром', 'title' => 'Рубка леса', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'СтройКом', 'title' => 'Строительная компания', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'ТРКом', 'title' => 'Транспортная компания', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'ПрБюро', 'title' => 'Проектное бюро', 'kind' => 'firm', 'workers' => 1],
            ['code' => 'Магазин', 'title' => 'Магазин-рынок', 'kind' => 'shop', 'workers' => 1],
            // 2: колодец и др. бригадные SKU (labor_workers).
            ['code' => 'РемеслФабр', 'title' => 'Ремесленная фабрика', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'ПшАгро', 'title' => 'Сбор пшеницы', 'kind' => 'firm', 'workers' => 1],
            ['code' => 'ОвФерма', 'title' => 'Овечья ферма', 'kind' => 'firm', 'workers' => 1],
            ['code' => 'КорФерма', 'title' => 'Коровья ферма', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'ШвейФаб', 'title' => 'Швейная фабрика', 'kind' => 'firm', 'workers' => 1],
            ['code' => 'ХлопАгро', 'title' => 'Хлопок и ткани', 'kind' => 'firm', 'workers' => 1],
            // Фаза 0: не отдельные компании — шаблоны остаются, но is_active=0.
            ['code' => 'Пекарня', 'title' => 'Пекарня', 'kind' => 'firm', 'workers' => 1, 'active' => false],
            ['code' => 'МолокоЗавод', 'title' => 'Молоко и сыр', 'kind' => 'firm', 'workers' => 1, 'active' => false],
            ['code' => 'Харчевня', 'title' => 'Харчевня', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'КамДоб', 'title' => 'Добыча камня', 'kind' => 'firm', 'workers' => 2],
            ['code' => 'КурФерм', 'title' => 'Птицеферма', 'kind' => 'firm', 'workers' => 1],
        ];
    }

    /** Синк is_active + workers_default по канону (идемпотентно). */
    public function syncFirmActivity(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ref_firm SET is_active = ?, workers_default = ? WHERE code = ?'
        );
        foreach (self::firmCanon() as $f) {
            $active = array_key_exists('active', $f) ? (int) (bool) $f['active'] : 1;
            $stmt->execute([$active, (int) $f['workers'], $f['code']]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFirms(bool $withProducts = true): array
    {
        $rows = $this->pdo->query(
            'SELECT * FROM ref_firm WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
        if (!$withProducts) {
            return $rows;
        }
        $byCode = [];
        foreach ($rows as $r) {
            $r['products'] = [];
            $byCode[(string) $r['code']] = $r;
        }
        if ($byCode === []) {
            return [];
        }
        $stmt = $this->pdo->query(
            'SELECT fp.*, s.name_ru, s.unit, s.kind AS sku_kind, s.icon_key, s.labor_min
             FROM ref_firm_product fp
             LEFT JOIN ref_sku s ON s.sku_key = fp.sku_key
             ORDER BY fp.firm_code ASC, fp.sort_order ASC, fp.id ASC'
        );
        while ($p = $stmt->fetch()) {
            $code = (string) $p['firm_code'];
            if (!isset($byCode[$code])) {
                continue;
            }
            $byCode[$code]['products'][] = $p;
        }
        return array_values($byCode);
    }

    public function getFirm(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ref_firm WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['products'] = $this->listFirmProducts($code);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function listFirmProducts(string $code): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fp.*, s.name_ru, s.unit, s.kind AS sku_kind, s.icon_key, s.labor_min
             FROM ref_firm_product fp
             LEFT JOIN ref_sku s ON s.sku_key = fp.sku_key
             WHERE fp.firm_code = ?
             ORDER BY fp.sort_order ASC, fp.id ASC'
        );
        $stmt->execute([$code]);
        return $stmt->fetchAll();
    }

    /**
     * Обновить карточку шаблона фирмы.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateFirm(string $code, array $body): array
    {
        if (!$this->getFirm($code)) {
            throw new \InvalidArgumentException('Фирма не найдена: ' . $code);
        }
        $sets = [];
        $params = ['code' => $code];
        foreach (['title', 'kind', 'notes'] as $c) {
            if (!array_key_exists($c, $body)) {
                continue;
            }
            $v = $body[$c];
            if ($v === '') {
                $v = null;
            }
            $sets[] = '`' . $c . '` = :' . $c;
            $params[$c] = $v;
        }
        foreach (['workers_default', 'sort_order', 'is_active'] as $c) {
            if (!array_key_exists($c, $body)) {
                continue;
            }
            $sets[] = '`' . $c . '` = :' . $c;
            $params[$c] = (int) $body[$c];
        }
        if ($sets === []) {
            throw new \InvalidArgumentException('Нет данных');
        }
        $sql = 'UPDATE ref_firm SET ' . implode(', ', $sets) . ' WHERE code = :code';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        return $this->getFirm($code) ?? [];
    }

    /**
     * Заменить линейку продукции шаблона.
     *
     * @param list<mixed> $products
     * @return list<array<string, mixed>>
     */
    public function replaceFirmProducts(string $code, array $products): array
    {
        if (!$this->getFirm($code)) {
            throw new \InvalidArgumentException('Фирма не найдена: ' . $code);
        }
        $this->pdo->prepare('DELETE FROM ref_firm_product WHERE firm_code = ?')->execute([$code]);
        $ins = $this->pdo->prepare(
            'INSERT INTO ref_firm_product (firm_code, sku_key, qty_day, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        $order = 0;
        $seen = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $sku = trim((string) ($p['sku_key'] ?? ''));
            if ($sku === '' || isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;
            $qty = $p['qty_day'] ?? null;
            if ($qty === '' || $qty === null) {
                $qty = null;
            } else {
                $qty = self::ceil2($qty);
            }
            $ins->execute([
                $code,
                $sku,
                $qty,
                (int) ($p['sort_order'] ?? $order),
            ]);
            $order++;
        }
        return $this->listFirmProducts($code);
    }

    /**
     * Посев шаблонов фирм + линейка из ref_sku.producer_code.
     *
     * @return array{firms:int, products:int, skipped:bool}
     */
    public function seedFirms(bool $force = false): array
    {
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM ref_firm')->fetchColumn();
        if ($n > 0 && !$force) {
            $this->syncFirmActivity();
            return ['firms' => 0, 'products' => 0, 'skipped' => true];
        }
        if ($force) {
            $this->pdo->exec('DELETE FROM ref_firm_product');
            $this->pdo->exec('DELETE FROM ref_firm');
        }

        $canon = self::firmCanon();
        $byCode = [];
        foreach ($canon as $i => $f) {
            $byCode[$f['code']] = $f + ['sort_order' => $i];
        }

        // Дополнительные коды из каталога SKU (если появились вручную).
        $extra = $this->pdo->query(
            "SELECT DISTINCT producer_code FROM ref_sku
             WHERE producer_code IS NOT NULL AND producer_code != ''
             ORDER BY producer_code"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($extra as $code) {
            $code = (string) $code;
            if ($code === 'Дерево' || $code === 'ГлинДоб') {
                continue;
            }
            if (!isset($byCode[$code])) {
                $byCode[$code] = [
                    'code' => $code,
                    'title' => $code,
                    'kind' => 'firm',
                    'workers' => 0,
                    'sort_order' => 900 + count($byCode),
                    'active' => true,
                ];
            }
        }

        $insFirm = $this->pdo->prepare(
            'INSERT INTO ref_firm (code, title, kind, workers_default, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $firms = 0;
        foreach ($byCode as $f) {
            $active = array_key_exists('active', $f) ? (int) (bool) $f['active'] : 1;
            $insFirm->execute([
                $f['code'],
                $f['title'],
                $f['kind'],
                (int) $f['workers'],
                (int) $f['sort_order'],
                $active,
            ]);
            $firms++;
        }

        // Норма шт/день ≈ произв. день: (300 / labor_min) * 0.9 при наличии labor_min.
        $skus = $this->pdo->query(
            "SELECT sku_key, producer_code, labor_min, sort_order
             FROM ref_sku
             WHERE is_active = 1
               AND producer_code IS NOT NULL AND producer_code != ''
             ORDER BY sort_order ASC, id ASC"
        )->fetchAll();
        $insProd = $this->pdo->prepare(
            'INSERT INTO ref_firm_product (firm_code, sku_key, qty_day, sort_order)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty_day = VALUES(qty_day)'
        );
        $products = 0;
        $orderByFirm = [];
        foreach ($skus as $s) {
            $code = (string) $s['producer_code'];
            if ($code === 'Дерево') {
                $code = 'ЛесПром';
            }
            if ($code === 'ГлинДоб') {
                $code = 'ГонПром';
            }
            if (!isset($byCode[$code])) {
                continue;
            }
            $labor = $s['labor_min'] !== null ? (float) $s['labor_min'] : null;
            $qtyDay = null;
            if ($labor !== null && $labor > 0) {
                $qtyDay = self::ceil2((300.0 / $labor) * 0.9);
            }
            $ord = $orderByFirm[$code] ?? 0;
            $orderByFirm[$code] = $ord + 1;
            $insProd->execute([$code, $s['sku_key'], $qtyDay, $ord]);
            $products++;
        }

        $this->syncFirmActivity();
        return ['firms' => $firms, 'products' => $products, 'skipped' => false];
    }

    /**
     * Привязать продукцию к шаблонам фирм по producer_code из catalog_seed.json.
     * Существующие строки (и ручные qty_day) не трогаются — только дописываются недостающие.
     *
     * @return array{added:int, skipped:int, unknown:list<string>}
     */
    public function attachFirmProductsFromSeed(): array
    {
        $path = dirname(__DIR__) . '/data/catalog_seed.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Нет файла сида: data/catalog_seed.json');
        }
        $items = json_decode((string) file_get_contents($path), true);
        if (!is_array($items)) {
            throw new \RuntimeException('Сид не читается как JSON-массив');
        }

        $firmByLower = [];
        foreach ($this->pdo->query('SELECT code FROM ref_firm')->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $firmByLower[mb_strtolower((string) $code)] = (string) $code;
        }
        $aliases = ['дерево' => 'ЛесПром', 'глиндоб' => 'ГонПром'];

        $existing = [];
        $maxOrder = [];
        foreach ($this->pdo->query('SELECT firm_code, sku_key, sort_order FROM ref_firm_product')->fetchAll() as $r) {
            $existing[$r['firm_code'] . '|' . $r['sku_key']] = true;
            $maxOrder[$r['firm_code']] = max($maxOrder[$r['firm_code']] ?? -1, (int) $r['sort_order']);
        }
        $skuExists = array_flip($this->pdo->query('SELECT sku_key FROM ref_sku')->fetchAll(PDO::FETCH_COLUMN));

        $ins = $this->pdo->prepare(
            'INSERT INTO ref_firm_product (firm_code, sku_key, qty_day, sort_order) VALUES (?, ?, ?, ?)'
        );
        $added = 0;
        $skipped = 0;
        $unknown = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $sku = trim((string) ($it['sku_key'] ?? ''));
            $raw = trim((string) ($it['producer_code'] ?? ''));
            if ($sku === '' || $raw === '' || !isset($skuExists[$sku])) {
                $skipped++;
                continue;
            }
            $lower = mb_strtolower($raw);
            $firm = $aliases[$lower] ?? ($firmByLower[$lower] ?? null);
            if ($firm === null) {
                $unknown[$raw] = true;
                $skipped++;
                continue;
            }
            if (isset($existing[$firm . '|' . $sku])) {
                $skipped++;
                continue;
            }
            $labor = isset($it['labor_min']) && $it['labor_min'] !== null ? (float) $it['labor_min'] : null;
            $qtyDay = ($labor !== null && $labor > 0) ? self::ceil2((300.0 / $labor) * 0.9) : null;
            $ord = ($maxOrder[$firm] ?? -1) + 1;
            $maxOrder[$firm] = $ord;
            $ins->execute([$firm, $sku, $qtyDay, $ord]);
            $existing[$firm . '|' . $sku] = true;
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped, 'unknown' => array_keys($unknown)];
    }

    /**
     * Конкретные фирмы поселения «Макленд» (последний мир с таким поселением).
     *
     * @return array{settlement:?array<string,mixed>, fiscal:array<string,mixed>, items:list<array<string,mixed>>, note?:string}
     */
    public function listMaklendCompanies(): array
    {
        $settlement = $this->findMaklendSettlement();
        $fiscal = $this->resolveFiscal($settlement ? (int) $settlement['world_id'] : null);
        if (!$settlement) {
            return [
                'settlement' => null,
                'fiscal' => $fiscal,
                'items' => [],
                'note' => 'Нет поселения «Макленд» — создайте или сбросьте мир (фаза 0: 1 деревня × 30).',
            ];
        }
        $this->ensureCompanyLinesBackfill((int) $settlement['id']);
        return [
            'settlement' => $settlement,
            'fiscal' => $fiscal,
            'items' => $this->listSettlementCompanies((int) $settlement['id'], $fiscal),
        ];
    }

    /** @return array<string, mixed>|null */
    private function findMaklendSettlement(): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT s.id, s.world_id, s.name, s.population, w.name AS world_name, w.day, w.month, w.year
             FROM settlements s
             INNER JOIN worlds w ON w.id = s.world_id
             WHERE s.name = 'Макленд'
             ORDER BY s.id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Для старых миров: дописать company_lines из шаблона, если пусто. */
    private function ensureCompanyLinesBackfill(int $settlementId): void
    {
        $cos = $this->pdo->prepare(
            'SELECT id, code, COALESCE(template_code, code) AS template_code FROM companies WHERE settlement_id = ?'
        );
        $cos->execute([$settlementId]);
        $cnt = $this->pdo->prepare('SELECT COUNT(*) FROM company_lines WHERE company_id = ?');
        $ins = $this->pdo->prepare(
            'INSERT INTO company_lines (company_id, sku_key, qty_day, sort_order)
             SELECT ?, sku_key, qty_day, sort_order FROM ref_firm_product WHERE firm_code = ?'
        );
        $setTpl = $this->pdo->prepare(
            'UPDATE companies SET template_code = COALESCE(template_code, code) WHERE id = ?'
        );
        foreach ($cos->fetchAll() as $c) {
            $setTpl->execute([(int) $c['id']]);
            $cnt->execute([(int) $c['id']]);
            if ((int) $cnt->fetchColumn() > 0) {
                continue;
            }
            $ins->execute([(int) $c['id'], (string) $c['template_code']]);
        }
    }

    /**
     * @param array<string, mixed> $fiscal
     * @return list<array<string, mixed>>
     */
    public function listSettlementCompanies(int $settlementId, array $fiscal): array
    {
        $dayMin = (float) ($fiscal['labor_min_day'] ?? 300);
        $monthMin = (float) ($fiscal['labor_min_month'] ?? 2700);
        $workDays = $dayMin > 0 ? ($monthMin / $dayMin) : 9.0;

        $rows = $this->pdo->prepare(
            'SELECT c.*, w.balance AS cash
             FROM companies c
             LEFT JOIN wallets w
               ON w.world_id = c.world_id AND w.owner_type = \'company\' AND w.owner_id = c.id
             WHERE c.settlement_id = ?
             ORDER BY c.id ASC'
        );
        $rows->execute([$settlementId]);
        $byId = [];
        foreach ($rows->fetchAll() as $r) {
            $r['products'] = [];
            $r['capacity_min_day'] = self::ceil2(((int) $r['workers']) * $dayMin);
            $r['used_min_day'] = 0.0;
            $r['work_days_month'] = self::ceil2($workDays);
            $byId[(int) $r['id']] = $r;
        }
        if ($byId === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', array_keys($byId)));
        $prod = $this->pdo->query(
            "SELECT cl.*, s.name_ru, s.unit, s.kind AS sku_kind, s.icon_key, s.labor_min
             FROM company_lines cl
             LEFT JOIN ref_sku s ON s.sku_key = cl.sku_key
             WHERE cl.company_id IN ($ids)
             ORDER BY cl.company_id ASC, cl.sort_order ASC, cl.id ASC"
        );
        while ($p = $prod->fetch()) {
            $cid = (int) $p['company_id'];
            if (!isset($byId[$cid])) {
                continue;
            }
            $labor = $p['labor_min'] !== null ? (float) $p['labor_min'] : null;
            $qty = $p['qty_day'] !== null ? (float) $p['qty_day'] : null;
            $minDay = ($labor !== null && $qty !== null && $labor > 0 && $qty > 0)
                ? self::ceil2($qty * $labor)
                : null;
            $p['min_day'] = $minDay;
            $p['qty_month'] = ($qty !== null) ? self::ceil2($qty * $workDays) : null;
            if ($minDay !== null) {
                $byId[$cid]['used_min_day'] = self::ceil2(
                    (float) $byId[$cid]['used_min_day'] + $minDay
                );
            }
            $byId[$cid]['products'][] = $p;
        }

        foreach ($byId as &$co) {
            $cap = (float) ($co['capacity_min_day'] ?? 0);
            $used = (float) ($co['used_min_day'] ?? 0);
            $co['load_pct'] = $cap > 0 ? self::ceil2(($used / $cap) * 100) : null;
            $co['over_capacity'] = $cap > 0 && $used > $cap + 0.001;
        }
        unset($co);

        return array_values($byId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateMaklendCompany(int $companyId, array $body): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.* FROM companies c
             INNER JOIN settlements s ON s.id = c.settlement_id
             WHERE c.id = ? AND s.name = 'Макленд' LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $co = $stmt->fetch();
        if (!$co) {
            throw new \InvalidArgumentException('Компания Макленд не найдена');
        }

        $sets = [];
        $params = ['id' => $companyId];
        foreach (['title', 'kind'] as $c) {
            if (!array_key_exists($c, $body)) {
                continue;
            }
            $sets[] = '`' . $c . '` = :' . $c;
            $params[$c] = $body[$c] === '' ? null : $body[$c];
        }
        if (array_key_exists('workers', $body)) {
            $sets[] = '`workers` = :workers';
            $params['workers'] = (int) $body['workers'];
        }
        if ($sets !== []) {
            $sql = 'UPDATE companies SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $upd = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $upd->bindValue(':' . $k, $v);
            }
            $upd->execute();
        }

        if (array_key_exists('products', $body) && is_array($body['products'])) {
            $this->replaceCompanyLines($companyId, $body['products']);
        }

        $fiscal = $this->resolveFiscal((int) $co['world_id']);
        $list = $this->listSettlementCompanies((int) $co['settlement_id'], $fiscal);
        foreach ($list as $row) {
            if ((int) $row['id'] === $companyId) {
                return $row;
            }
        }
        throw new \RuntimeException('Компания не найдена после сохранения');
    }

    /** @param list<mixed> $products */
    public function replaceCompanyLines(int $companyId, array $products): void
    {
        $this->pdo->prepare('DELETE FROM company_lines WHERE company_id = ?')->execute([$companyId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO company_lines (company_id, sku_key, qty_day, qty_fact, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $order = 0;
        $seen = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $sku = trim((string) ($p['sku_key'] ?? ''));
            if ($sku === '' || isset($seen[$sku])) {
                continue;
            }
            $seen[$sku] = true;
            $qty = $p['qty_day'] ?? null;
            if ($qty === '' || $qty === null) {
                $qty = null;
            } else {
                $qty = self::ceil2($qty);
            }
            $fact = $p['qty_fact'] ?? null;
            if ($fact === '' || $fact === null) {
                $fact = null;
            } else {
                $fact = self::ceil2($fact);
            }
            $ins->execute([$companyId, $sku, $qty, $fact, (int) ($p['sort_order'] ?? $order)]);
            $order++;
        }
    }

    public function ensureDefaultFiscal(): void
    {
        $n = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ref_fiscal WHERE scope_type = 'global'"
        )->fetchColumn();
        if ($n > 0) {
            return;
        }
        $this->upsertFiscal([
            'scope_type' => 'global',
            'title' => 'Канон старта',
            'vat_rate' => 0.07,
            'shop_margin_rate' => 0.10,
            'producer_margin_rate' => 0.20,
            'util_rate' => 0.90,
            'labor_min_hour' => 50,
            'labor_min_day' => 300,
            'labor_min_month' => 2700,
        ]);
    }

    /**
     * Список построек (kind=building) с парным проектом.
     *
     * @return list<array<string, mixed>>
     */
    public function listBuildings(): array
    {
        $rows = $this->pdo->query(
            "SELECT * FROM ref_sku WHERE kind = 'building' AND is_active = 1
             ORDER BY sort_order ASC, name_ru ASC"
        )->fetchAll();
        $out = [];
        foreach ($rows as $b) {
            $b['ingredients'] = $this->listIngredients((int) $b['id']);
            $pairKey = (string) ($b['pair_sku_key'] ?? '');
            $project = null;
            if ($pairKey !== '') {
                $stmt = $this->pdo->prepare('SELECT * FROM ref_sku WHERE sku_key = ? LIMIT 1');
                $stmt->execute([$pairKey]);
                $project = $stmt->fetch() ?: null;
                if ($project) {
                    $project['ingredients'] = $this->listIngredients((int) $project['id']);
                }
            }
            $out[] = [
                'building' => $b,
                'project' => $project,
                'scheme' => $this->calcBuildingScheme([
                    'width_cm' => $b['width_cm'],
                    'length_cm' => $b['length_cm'],
                    'height_cm' => $b['height_cm'],
                    'partitions' => $b['build_partitions'] ?? 1,
                    'windows' => $b['build_windows'] ?? 2,
                    'doors' => $b['build_doors'] ?? 2,
                ]),
            ];
        }
        return $out;
    }

    /**
     * Создать/обновить пару: здание (building) + проект (project), привязать к фирмам.
     *
     * @param array<string, mixed> $body
     * @return array{building:array<string,mixed>, project:array<string,mixed>, scheme:array<string,mixed>}
     */
    public function upsertBuildingPair(array $body): array
    {
        $name = trim((string) ($body['name_ru'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Нужно название здания');
        }
        $buildKey = trim((string) ($body['sku_key'] ?? ''));
        if ($buildKey === '') {
            $buildKey = self::slugKey($name);
        }
        if ($buildKey === '') {
            throw new \InvalidArgumentException('Не удалось получить sku_key');
        }
        $projectKey = trim((string) ($body['project_sku_key'] ?? ''));
        if ($projectKey === '') {
            $projectKey = 'proekt_' . $buildKey;
            if (strlen($projectKey) > 64) {
                $projectKey = substr($projectKey, 0, 64);
            }
        }

        $W = (float) ($body['width_cm'] ?? 0);
        $D = (float) ($body['length_cm'] ?? 0);
        $H = (float) ($body['height_cm'] ?? 0);
        $partitions = (int) ($body['build_partitions'] ?? 1);
        $windows = (int) ($body['build_windows'] ?? 2);
        $doors = (int) ($body['build_doors'] ?? 2);
        $scheme = $this->calcBuildingScheme([
            'width_cm' => $W,
            'length_cm' => $D,
            'height_cm' => $H,
            'partitions' => $partitions,
            'windows' => $windows,
            'doors' => $doors,
        ]);
        if (!($scheme['ok'] ?? false)) {
            throw new \InvalidArgumentException((string) ($scheme['error'] ?? 'Ошибка схемы'));
        }

        $builder = trim((string) ($body['builder_code'] ?? 'СтройКом')) ?: 'СтройКом';
        $bureau = trim((string) ($body['bureau_code'] ?? 'ПрБюро')) ?: 'ПрБюро';
        $rooms = max(1, $partitions + 1);

        $buildIngs = [];
        $names = [
            'brevno' => 'Бревно',
            'kamen_dlya_pola' => 'Камень для пола',
            'doska' => 'Доска',
            'glina_1_kg' => 'Глина 1 кг',
            'shtory' => 'Шторы',
        ];
        $i = 0;
        foreach ($scheme['qty'] as $k => $qty) {
            if (!($qty > 0)) {
                continue;
            }
            $buildIngs[] = [
                'ingredient_sku_key' => $k,
                'ingredient_name_ru' => $names[$k] ?? $k,
                'qty' => $qty,
                'sort_order' => $i++,
            ];
        }

        $notes = sprintf(
            "Сруб %g×%g×%g см (%d рядов). Комнат %d. Куб 60 см. Парный проект: %s.\nСырьё: брёвна %d, камень %d, доски %d, глина %d кг, шторы %d.",
            $W,
            $D,
            $scheme['wall_h_actual_cm'],
            $scheme['courses'],
            $rooms,
            $projectKey,
            $scheme['qty']['brevno'],
            $scheme['qty']['kamen_dlya_pola'],
            $scheme['qty']['doska'],
            $scheme['qty']['glina_1_kg'],
            $scheme['qty']['shtory']
        );

        $buildBody = [
            'sku_key' => $buildKey,
            'name_ru' => $name,
            'kind' => 'building',
            'product_type' => 'Здание',
            'product_kind' => sprintf('Здание %d ком', $rooms),
            'producer_code' => $builder,
            'unit' => 'Шт.',
            'shape' => 'box',
            'width_cm' => $W,
            'length_cm' => $D,
            'height_cm' => $H,
            'build_partitions' => $partitions,
            'build_windows' => $windows,
            'build_doors' => $doors,
            'pair_sku_key' => $projectKey,
            'labor_min' => $scheme['labor_min'],
            'labor_workers' => $scheme['labor_workers'],
            'shift_pct' => 100,
            'is_shop' => 0,
            'icon_key' => 'firm',
            'notes' => $notes,
            'sort_order' => (int) ($body['sort_order'] ?? 200),
            'is_active' => 1,
            'ingredients' => $buildIngs,
        ];

        $projLabor = max(150, (int) ceil($scheme['labor_min'] / 6));
        $projIngs = [
            [
                'ingredient_sku_key' => 'pischaya_bumaga_1_list',
                'ingredient_name_ru' => 'Писчая бумага 1 лист',
                'qty' => 10,
                'sort_order' => 0,
            ],
            [
                'ingredient_sku_key' => 'flakon_s_chernilami',
                'ingredient_name_ru' => 'Флакон с чернилами',
                'qty' => 0.5,
                'sort_order' => 1,
            ],
        ];
        $projBody = [
            'sku_key' => $projectKey,
            'name_ru' => 'Проект ' . mb_strtolower($name),
            'kind' => 'project',
            'product_type' => 'Услуга',
            'product_kind' => sprintf('Проект здания %d ком', $rooms),
            'producer_code' => $bureau,
            'unit' => 'Экз.',
            'shape' => 'none',
            'pair_sku_key' => $buildKey,
            'labor_min' => $projLabor,
            'labor_workers' => 1,
            'shift_pct' => 100,
            'is_shop' => 0,
            'icon_key' => 'service',
            'notes' => 'Парная постройка: ' . $buildKey,
            'sort_order' => (int) ($body['sort_order'] ?? 200) - 1,
            'is_active' => 1,
            'ingredients' => $projIngs,
        ];

        $existingBuild = $this->findSkuIdByKey($buildKey);
        $existingProj = $this->findSkuIdByKey($projectKey);

        $this->pdo->beginTransaction();
        try {
            if ($existingBuild) {
                $building = $this->updateSku($existingBuild, $buildBody);
            } else {
                $building = $this->createSku($buildBody);
            }
            if ($existingProj) {
                $oldProj = $this->getSku($existingProj);
                if ($oldProj && !empty($oldProj['name_ru'])) {
                    $projBody['name_ru'] = $oldProj['name_ru'];
                }
                $project = $this->updateSku($existingProj, $projBody);
            } else {
                $project = $this->createSku($projBody);
            }
            $this->repriceSkuId((int) $building['id']);
            $this->repriceSkuId((int) $project['id']);
            $this->ensureFirmProductLine($builder, $buildKey, $scheme['labor_min']);
            $this->ensureFirmProductLine($bureau, $projectKey, $projLabor);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $building = $this->getSku((int) $building['id']) ?? $building;
        $project = $this->getSku((int) $project['id']) ?? $project;

        return [
            'building' => $building,
            'project' => $project,
            'scheme' => $scheme,
        ];
    }

    /** @param array<string, mixed> $p */
    public function calcBuildingScheme(array $p): array
    {
        $logLen = 100.0;
        $logH = 15.0;
        $t = 15.0;
        $stoneA = 20.0 * 10.0;
        $boardA = 10.0 * 100.0;
        $pitch = 1.15;
        $clayPer = 25.0;
        $framePer = 4;
        $minPerLog = 8;
        $workers = 2;

        $W = (float) ($p['width_cm'] ?? 0);
        $D = (float) ($p['length_cm'] ?? 0);
        $H = (float) ($p['height_cm'] ?? 0);
        $partitions = max(0, (int) ($p['partitions'] ?? 1));
        $windows = max(0, (int) ($p['windows'] ?? 2));
        $doors = max(0, (int) ($p['doors'] ?? 2));

        if (!($W > 0) || !($D > 0) || !($H > 0)) {
            return ['ok' => false, 'error' => 'Нужны ширина, длина и высота стен (см)'];
        }

        $courses = (int) ceil($H / $logH);
        $cellsW = (int) ceil($W / $logLen);
        $cellsD = (int) ceil($D / $logLen);
        $outer = 2 * ($cellsW + $cellsD) - 4;
        if ($outer < 4) {
            $outer = 2 * ($cellsW + $cellsD);
        }
        $part = $partitions > 0 ? $cellsW : 0;
        $logs = ($outer + $partitions * $part) * $courses;
        $innerW = max(0.0, $W - 2 * $t);
        $innerD = max(0.0, $D - 2 * $t);
        $stones = $stoneA > 0 ? (int) ceil(($innerW * $innerD) / $stoneA) : 0;
        $roofBoards = $boardA > 0 ? (int) ceil(($W * $D * $pitch) / $boardA) : 0;
        $frameBoards = ($windows + $doors) * $framePer;
        $boards = $roofBoards + $frameBoards;
        $plaster = (2 * ($W + $D) * $H / 10000.0 + $partitions * $innerW * $H / 10000.0) * 2;
        $clay = (int) ceil($plaster * $clayPer);
        $curtains = $windows + $doors;
        $labor = (int) ceil($logs * $minPerLog);

        return [
            'ok' => true,
            'error' => null,
            'courses' => $courses,
            'wall_h_actual_cm' => $courses * $logH,
            'qty' => [
                'brevno' => $logs,
                'kamen_dlya_pola' => $stones,
                'doska' => $boards,
                'glina_1_kg' => $clay,
                'shtory' => $curtains,
            ],
            'labor_min' => $labor,
            'labor_workers' => $workers,
            'detail' => [
                'roof_boards' => $roofBoards,
                'frame_boards' => $frameBoards,
                'plaster_m2' => self::ceil2($plaster),
                'floor_m2' => self::ceil2(($innerW * $innerD) / 10000.0),
            ],
        ];
    }

    private function findSkuIdByKey(string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ref_sku WHERE sku_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function ensureFirmProductLine(string $firmCode, string $skuKey, float $laborMin): void
    {
        $firm = $this->pdo->prepare('SELECT code FROM ref_firm WHERE code = ? LIMIT 1');
        $firm->execute([$firmCode]);
        if (!$firm->fetchColumn()) {
            return;
        }
        $exists = $this->pdo->prepare(
            'SELECT id FROM ref_firm_product WHERE firm_code = ? AND sku_key = ? LIMIT 1'
        );
        $exists->execute([$firmCode, $skuKey]);
        if ($exists->fetchColumn()) {
            return;
        }
        $qtyDay = $laborMin > 0 ? self::ceil2((300.0 / $laborMin) * 0.9) : null;
        $ord = (int) $this->pdo->query(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ref_firm_product WHERE firm_code = '
            . $this->pdo->quote($firmCode)
        )->fetchColumn();
        $ins = $this->pdo->prepare(
            'INSERT INTO ref_firm_product (firm_code, sku_key, qty_day, sort_order) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$firmCode, $skuKey, $qtyDay, $ord]);
    }

    /** Пересчёт cost / price_internal по kind (building/project/tool: +НДС; service: без НДС). */
    private function repriceSkuId(int $id): void
    {
        $sku = $this->getSku($id);
        if (!$sku) {
            return;
        }
        $fiscal = $this->resolveFiscal(null);
        $vat = (float) ($fiscal['vat_rate'] ?? 0.07);
        $prodM = (float) ($fiscal['producer_margin_rate'] ?? 0.2);
        $util = (float) ($fiscal['util_rate'] ?? 0.9);
        $monthMin = (float) ($fiscal['labor_min_month'] ?? 2700);
        $fot = 100.0;

        $ingCost = 0.0;
        $priceStmt = $this->pdo->prepare(
            'SELECT kind, cost, price_internal, price_shop FROM ref_sku WHERE sku_key = ?'
        );
        foreach ($sku['ingredients'] ?? [] as $ing) {
            $key = (string) ($ing['ingredient_sku_key'] ?? '');
            $qty = (float) ($ing['qty'] ?? 0);
            if ($key === '' || !($qty > 0)) {
                continue;
            }
            $priceStmt->execute([$key]);
            $row = $priceStmt->fetch();
            if (!$row) {
                continue;
            }
            $p = null;
            $k = (string) $row['kind'];
            if (in_array($k, ['portion', 'internal', 'tool', 'project', 'building', 'service'], true)) {
                $p = $row['cost'] !== null ? (float) $row['cost'] : ($row['price_internal'] !== null ? (float) $row['price_internal'] : null);
            } else {
                foreach (['price_shop', 'price_internal', 'cost'] as $f) {
                    if ($row[$f] !== null) {
                        $p = (float) $row[$f];
                        break;
                    }
                }
            }
            if ($p !== null) {
                $ingCost += $p * $qty;
            }
        }

        $labor = (float) ($sku['labor_min'] ?? 0);
        $workers = max(1, (int) ($sku['labor_workers'] ?? 1));
        $shift = $sku['shift_pct'] !== null ? min(100.0, max(0.0, (float) $sku['shift_pct'])) / 100.0 : 1.0;
        $laborCost = 0.0;
        if ($labor > 0) {
            $prod90 = ($monthMin / $labor) * $util;
            if ($prod90 > 0) {
                $laborCost = ($fot * $workers * $shift) / $prod90;
            }
        }
        $cost = self::ceil2($ingCost + $laborCost) ?? 0.0;
        $withProfit = self::ceil2($cost * (1 + $prodM)) ?? $cost;
        $kind = (string) $sku['kind'];
        if (in_array($kind, ['building', 'project', 'tool'], true)) {
            $price = self::ceil2($withProfit * (1 + $vat));
        } elseif ($kind === 'service') {
            $price = $withProfit;
        } else {
            $price = $withProfit;
        }
        $upd = $this->pdo->prepare(
            'UPDATE ref_sku SET cost = ?, cost_seb = ?, price_internal = ?, price_shop = NULL WHERE id = ?'
        );
        $upd->execute([$cost, $cost, $price, $id]);
    }

    private static function slugKey(string $name): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
            'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
            'я' => 'ya',
        ];
        $out = '';
        $lower = mb_strtolower($name);
        $len = mb_strlen($lower);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($lower, $i, 1);
            if (isset($map[$ch])) {
                $out .= $map[$ch];
            } elseif (preg_match('/[a-z0-9]/u', $ch)) {
                $out .= $ch;
            } elseif (str_contains(' ./-+', $ch)) {
                $out .= '_';
            }
        }
        $out = preg_replace('/_+/', '_', $out) ?? $out;
        $out = trim($out, '_');
        return substr($out, 0, 64);
    }

    /** @param list<mixed> $ings */
    private function replaceIngredients(int $skuId, array $ings): void
    {
        $del = $this->pdo->prepare('DELETE FROM ref_sku_ingredient WHERE sku_id = ?');
        $del->execute([$skuId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO ref_sku_ingredient
             (sku_id, ingredient_sku_key, ingredient_name_ru, qty, unit_weight, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $order = 0;
        foreach ($ings as $ing) {
            if (!is_array($ing)) {
                continue;
            }
            $name = trim((string) ($ing['ingredient_name_ru'] ?? $ing['name_ru'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = $ing['ingredient_sku_key'] ?? null;
            if ($key === '') {
                $key = null;
            }
            $ins->execute([
                $skuId,
                $key,
                $name,
                self::qtyPrec($ing['qty'] ?? null),
                self::ceil2($ing['unit_weight'] ?? null),
                (int) ($ing['sort_order'] ?? $order),
            ]);
            $order++;
        }
    }

    /** Вверх до 0,01; сдвиг 1e6 гасит хвосты float. */
    private static function ceil2(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return ceil(round((float) $v * 1e6) / 1e4) / 100;
    }

    /** Кол-во в рецепте: до 4 знаков (1/60 пачки ≈ 0,0167). */
    private static function qtyPrec(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return round((float) $v, 4);
    }

    /**
     * Нормализация kind + зеркало sell_channel / cost_seb.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeKindAndPrices(array $data): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if ($kind === '' || !in_array($kind, self::KINDS, true)) {
            $ch = (string) ($data['sell_channel'] ?? 'shop');
            $kind = match ($ch) {
                'service' => 'service',
                'internal', 'none' => 'raw',
                'capital' => 'tool',
                default => 'good',
            };
            if (
                isset($data['price_shop'], $data['price_internal'])
                && $data['price_shop'] !== null
                && $data['price_internal'] !== null
                && $kind === 'good'
            ) {
                $kind = 'both';
            }
            if (!empty($data['is_tool']) && $kind === 'good') {
                $kind = 'tool';
            }
        }
        $data['kind'] = $kind;
        $data['is_tool'] = $kind === 'tool' ? 1 : 0;

        if (!isset($data['cost']) && isset($data['cost_seb'])) {
            $data['cost'] = $data['cost_seb'];
        }
        if (isset($data['cost']) && !isset($data['cost_seb'])) {
            $data['cost_seb'] = $data['cost'];
        } elseif (isset($data['cost'])) {
            $data['cost_seb'] = $data['cost'];
        }

        $hasShopPrice = array_key_exists('price_shop', $data)
            && $data['price_shop'] !== null
            && $data['price_shop'] !== '';

        $data['sell_channel'] = match ($kind) {
            'raw' => $hasShopPrice ? 'shop' : 'none',
            'internal' => 'internal',
            'portion' => 'none',
            'service' => 'service',
            'project' => 'service',
            'building' => 'capital',
            'pack' => 'shop',
            'tool' => 'capital',
            'both' => 'shop',
            default => 'shop',
        };

        // Монетка: конечный продукт, тара; сырьё — если есть цена магазина.
        if (in_array($kind, ['good', 'pack'], true)) {
            $data['is_shop'] = 1;
        } elseif ($kind === 'raw') {
            $data['is_shop'] = $hasShopPrice ? 1 : 0;
            if (!$hasShopPrice) {
                $data['sell_channel'] = 'none';
            }
        } else {
            $data['is_shop'] = 0;
        }

        if (in_array($kind, ['internal', 'service', 'tool', 'portion', 'project', 'building'], true)) {
            $data['price_shop'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function pickSku(array $body): array
    {
        $out = [];
        foreach (self::SKU_COLUMNS as $c) {
            if (!array_key_exists($c, $body)) {
                continue;
            }
            $v = $body[$c];
            if ($v === '') {
                $v = null;
            }
            if (in_array($c, [
                'labor_min', 'shift_pct', 'weight_kg', 'width_cm', 'length_cm', 'height_cm', 'density', 'pack_qty', 'contents_qty',
                'transport_trips_day',
                'vol_cyl_l', 'vol_box_l',
                'cost_amort', 'cost_transport', 'cost_tara',
                'cost', 'cost_seb', 'price_internal', 'price_shop', 'prod_month_90',
            ], true) && $v !== null) {
                $v = self::ceil2($v);
            }
            if (in_array($c, ['sort_order', 'is_active', 'is_tool', 'is_shop', 'labor_workers', 'build_partitions', 'build_windows', 'build_doors'], true) && $v !== null) {
                $v = (int) $v;
                if ($c === 'labor_workers' && $v < 1) {
                    $v = 1;
                }
            }
            $out[$c] = $v;
        }
        return $out;
    }
}
