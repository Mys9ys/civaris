<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

final class AdminService
{
    /** @var array<string, array{table:string, columns:list<string>, order:string}> */
    private const RESOURCES = [
        'families' => [
            'table' => 'ref_families',
            'columns' => ['group_key', 'group_name_ru', 'item_key', 'item_name_ru', 'role_note', 'sort_order', 'is_active'],
            'order' => 'group_key ASC, sort_order ASC, id ASC',
        ],
        'click-needs' => [
            'table' => 'ref_click_needs',
            'columns' => ['need_key', 'name_ru', 'period', 'amount', 'unit', 'how_closed', 'notes', 'sort_order', 'is_active'],
            'order' => 'sort_order ASC, id ASC',
        ],
        'catalog' => [
            'table' => 'ref_catalog',
            'columns' => [
                'sku_key', 'name_ru', 'item_type', 'item_type_ru',
                'food_subtype', 'food_subtype_ru',
                'category', 'category_ru', 'unit', 'kcal', 'notes', 'sort_order', 'is_active',
            ],
            'order' => 'item_type ASC, food_subtype ASC, sort_order ASC, id ASC',
        ],
        'knowledge' => [
            'table' => 'ref_knowledge',
            'columns' => ['block_num', 'block_key', 'block_name_ru', 'knowledge_key', 'name_ru', 'requires_text', 'unlocks_text', 'sort_order', 'at_click', 'is_active'],
            'order' => 'block_num ASC, sort_order ASC, id ASC',
        ],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public static function resourceKeys(): array
    {
        return array_keys(self::RESOURCES);
    }

    public function list(string $resource): array
    {
        $meta = $this->meta($resource);
        $sql = 'SELECT * FROM `' . $meta['table'] . '` ORDER BY ' . $meta['order'];
        return $this->pdo->query($sql)->fetchAll();
    }

    public function create(string $resource, array $body): array
    {
        $meta = $this->meta($resource);
        $data = $this->pick($meta['columns'], $body);
        if ($data === []) {
            throw new \InvalidArgumentException('Нет данных для создания');
        }
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $meta['table'],
            implode('`,`', $cols),
            implode(',', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        return $this->get($resource, (int) $this->pdo->lastInsertId());
    }

    public function update(string $resource, int $id, array $body): array
    {
        $meta = $this->meta($resource);
        $existing = $this->get($resource, $id);
        if (!$existing) {
            throw new \RuntimeException('Запись не найдена', 404);
        }
        $data = $this->pick($meta['columns'], $body);
        if ($data === []) {
            throw new \InvalidArgumentException('Нет данных для обновления');
        }
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = '`' . $col . '` = :' . $col;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE id = :id', $meta['table'], implode(', ', $sets));
        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $this->get($resource, $id);
    }

    public function delete(string $resource, int $id): void
    {
        $meta = $this->meta($resource);
        $stmt = $this->pdo->prepare('DELETE FROM `' . $meta['table'] . '` WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Запись не найдена', 404);
        }
    }

    public function get(string $resource, int $id): ?array
    {
        $meta = $this->meta($resource);
        $stmt = $this->pdo->prepare('SELECT * FROM `' . $meta['table'] . '` WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array{table:string, columns:list<string>, order:string} */
    private function meta(string $resource): array
    {
        if (!isset(self::RESOURCES[$resource])) {
            throw new \InvalidArgumentException('Неизвестный справочник: ' . $resource);
        }
        return self::RESOURCES[$resource];
    }

    /**
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function pick(array $columns, array $body): array
    {
        $out = [];
        foreach ($columns as $col) {
            if (!array_key_exists($col, $body)) {
                continue;
            }
            $val = $body[$col];
            if ($val === '') {
                $val = null;
            }
            if (in_array($col, ['sort_order', 'block_num', 'is_active', 'at_click'], true) && $val !== null) {
                $val = (int) $val;
            }
            if (in_array($col, ['amount', 'kcal'], true) && $val !== null) {
                $val = (float) $val;
            }
            $out[$col] = $val;
        }
        return $out;
    }
}
