<?php

declare(strict_types=1);

namespace Civaris;

use PDO;

final class Schema
{
    public static function ensure(PDO $pdo): void
    {
        self::ensureUgodiya($pdo);
        self::ensureChronicleSettlement($pdo);
        self::ensurePeopleJobs($pdo);
    }

    private static function ensureUgodiya(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settlement_ugodiya (
              id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              settlement_id INT UNSIGNED NOT NULL,
              sku_key       VARCHAR(64) NOT NULL,
              qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
              CONSTRAINT fk_ugodiya_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
              UNIQUE KEY uq_ugodiya_settlement_sku (settlement_id, sku_key),
              INDEX idx_ugodiya_sku (sku_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function ensureChronicleSettlement(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM chronicle LIKE 'settlement_id'");
        if ($stmt && $stmt->fetch()) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE chronicle
               ADD COLUMN settlement_id INT UNSIGNED NULL AFTER world_id,
               ADD INDEX idx_chronicle_settlement (settlement_id)'
        );
    }

    private static function ensurePeopleJobs(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM people LIKE 'job_key'");
        if ($stmt && $stmt->fetch()) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE people
               ADD COLUMN job_key VARCHAR(32) NOT NULL DEFAULT \'gatherer\' AFTER status,
               ADD COLUMN job_crop VARCHAR(32) NULL AFTER job_key,
               ADD INDEX idx_people_job (job_key)'
        );
    }
}
