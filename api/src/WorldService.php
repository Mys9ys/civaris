<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

final class WorldService
{
    public function __construct(
        private PDO $pdo,
        private array $appConfig
    ) {
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, population_cap, population, day, month, year, status, created_at
             FROM worlds WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getOwned(int $worldId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, population_cap, population, day, month, year, status
             FROM worlds WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$worldId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Creates world shell; poleis seeding comes in phase 1. */
    public function create(int $userId, string $name): array
    {
        $cap = (int) $this->appConfig['free_population_cap'];
        $startPop = (int) $this->appConfig['start_population'];
        $poleis = (int) $this->appConfig['start_poleis'];
        $perPolis = (int) $this->appConfig['people_per_polis'];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO worlds (user_id, name, population_cap, population, day, month, year)
                 VALUES (?, ?, ?, ?, 1, 1, 1)'
            );
            $stmt->execute([$userId, $name, $cap, $startPop]);
            $worldId = (int) $this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                'INSERT INTO settlements (world_id, name, kind, is_capital, pos_x, pos_y, population)
                 VALUES (?, ?, \'polis\', 1, ?, ?, ?)'
            );

            // Rough ring placement — equal-ish spacing stub for map later
            for ($i = 0; $i < $poleis; $i++) {
                $angle = (2 * M_PI * $i) / $poleis;
                $radius = 400;
                $x = (int) round(500 + $radius * cos($angle));
                $y = (int) round(500 + $radius * sin($angle));
                $ins->execute([
                    $worldId,
                    'Полис ' . ($i + 1),
                    $x,
                    $y,
                    $perPolis,
                ]);
            }

            $this->pdo->prepare(
                'INSERT INTO chronicle (world_id, day, month, year, event_type, message)
                 VALUES (?, 1, 1, 1, ?, ?)'
            )->execute([
                $worldId,
                'world_created',
                sprintf(
                    'Щелчок календаря. Основано %d полисов по %d человечков (всего %d). Free-cap: %d.',
                    $poleis,
                    $perPolis,
                    $startPop,
                    $cap
                ),
            ]);

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
            'SELECT id, day, month, year, event_type, message, created_at
             FROM chronicle WHERE world_id = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $worldId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function settlements(int $worldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, kind, is_capital, pos_x, pos_y, population
             FROM settlements WHERE world_id = ? ORDER BY id'
        );
        $stmt->execute([$worldId]);
        return $stmt->fetchAll();
    }

    /** Phase 0 day tick: advance calendar + chronicle stub. Full pipeline later. */
    public function advanceDay(int $worldId, int $userId): array
    {
        $world = $this->getOwned($worldId, $userId);
        if (!$world) {
            throw new \RuntimeException('Мир не найден', 404);
        }

        $day = (int) $world['day'];
        $month = (int) $world['month'];
        $year = (int) $world['year'];

        $day++;
        if ($day > 6) {
            $day = 1;
            $month++;
            if ($month > 4) {
                $month = 1;
                $year++;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE worlds SET day = ?, month = ?, year = ? WHERE id = ? AND user_id = ?'
            )->execute([$day, $month, $year, $worldId, $userId]);

            $this->pdo->prepare(
                'INSERT INTO chronicle (world_id, day, month, year, event_type, message)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $worldId,
                $day,
                $month,
                $year,
                'day_advanced',
                sprintf('Наступил %d день %d месяца %d года. (Пайплайн хозяйства — фаза 1)', $day, $month, $year),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getOwned($worldId, $userId) ?? [];
    }
}
