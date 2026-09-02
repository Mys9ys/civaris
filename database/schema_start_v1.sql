-- Civaris: стартовое состояние мира (срез A) — малая карта 5×20
-- mysql ... --default-character-set=utf8mb4 < database/schema_start_v1.sql
USE civaris;
SET NAMES utf8mb4;

ALTER TABLE worlds
  ADD COLUMN IF NOT EXISTS map_size ENUM('small','medium','large') NOT NULL DEFAULT 'small'
    AFTER status,
  ADD COLUMN IF NOT EXISTS map_seed INT UNSIGNED NULL
    AFTER map_size;

ALTER TABLE settlements
  ADD COLUMN IF NOT EXISTS province_id SMALLINT UNSIGNED NULL
    AFTER population,
  ADD COLUMN IF NOT EXISTS housing_kind VARCHAR(32) NOT NULL DEFAULT 'dormitory'
    AFTER province_id,
  ADD COLUMN IF NOT EXISTS housing_capacity INT UNSIGNED NOT NULL DEFAULT 20
    AFTER housing_kind;

CREATE TABLE IF NOT EXISTS people (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id      INT UNSIGNED NOT NULL,
  settlement_id INT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,
  energy        TINYINT UNSIGNED NOT NULL DEFAULT 100,
  status        ENUM('alive','dead') NOT NULL DEFAULT 'alive',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_people_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  CONSTRAINT fk_people_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  INDEX idx_people_world (world_id),
  INDEX idx_people_settlement (settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settlement_stock (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settlement_id INT UNSIGNED NOT NULL,
  sku_key       VARCHAR(64) NOT NULL,
  qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
  CONSTRAINT fk_stock_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
  UNIQUE KEY uq_settlement_sku (settlement_id, sku_key),
  INDEX idx_stock_sku (sku_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ref_click_needs
  (need_key, name_ru, period, amount, unit, how_closed, notes, sort_order, is_active)
VALUES
  ('kcal_day', 'Калории', 'day', 300.000, 'ккал', 'угодья → склад → выдача', 'Норма щелчка v1; БЖУ не используем', 10, 1),
  ('water_day', 'Вода', 'day', 1.000, 'л', 'ёмкости / горшки', '1 л/сутки на человека', 20, 1)
ON DUPLICATE KEY UPDATE
  name_ru = VALUES(name_ru),
  period = VALUES(period),
  amount = VALUES(amount),
  unit = VALUES(unit),
  how_closed = VALUES(how_closed),
  notes = VALUES(notes),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);
