-- Civaris: угодья отдельно от склада (на ветвях / в поле)
-- mysql ... --default-character-set=utf8mb4 < database/schema_ugodiya_v1.sql
USE civaris;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settlement_ugodiya (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settlement_id INT UNSIGNED NOT NULL,
  sku_key       VARCHAR(64) NOT NULL,
  qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
  CONSTRAINT fk_ugodiya_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  UNIQUE KEY uq_ugodiya_settlement_sku (settlement_id, sku_key),
  INDEX idx_ugodiya_sku (sku_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
