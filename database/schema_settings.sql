-- Civaris: справочники админки (/settings) + админ user#1
USE civaris;
SET NAMES utf8mb4;

-- Семейства мира (животные…водная флора/фауна)
CREATE TABLE IF NOT EXISTS ref_families (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_key    VARCHAR(64) NOT NULL COMMENT 'latin: animals, insects…',
  group_name_ru VARCHAR(120) NOT NULL,
  item_key     VARCHAR(64) NOT NULL COMMENT 'latin: sheep, copper…',
  item_name_ru VARCHAR(120) NOT NULL,
  role_note    VARCHAR(255) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_family_item (group_key, item_key),
  INDEX idx_families_group (group_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Потребности на щелчке
CREATE TABLE IF NOT EXISTS ref_click_needs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  need_key    VARCHAR(64) NOT NULL UNIQUE COMMENT 'latin: food_bju, water_day…',
  name_ru     VARCHAR(120) NOT NULL,
  period      ENUM('day','week','once','other') NOT NULL DEFAULT 'day',
  amount      DECIMAL(12,3) NULL,
  unit        VARCHAR(32) NULL COMMENT 'л, г, шт…',
  how_closed  VARCHAR(255) NULL COMMENT 'как закрывается',
  notes       VARCHAR(500) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Каталог товаров v0
CREATE TABLE IF NOT EXISTS ref_catalog (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku_key      VARCHAR(64) NOT NULL UNIQUE COMMENT 'latin: apple, pot_1l…',
  name_ru      VARCHAR(120) NOT NULL,
  category     VARCHAR(64) NOT NULL DEFAULT 'other' COMMENT 'fruit, pottery, water…',
  category_ru  VARCHAR(120) NULL,
  unit         VARCHAR(32) NULL,
  notes        VARCHAR(500) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ветка знаний (блоки 0–8)
CREATE TABLE IF NOT EXISTS ref_knowledge (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  block_num       TINYINT UNSIGNED NOT NULL COMMENT '0..8',
  block_key       VARCHAR(64) NOT NULL COMMENT 'latin: click, village…',
  block_name_ru   VARCHAR(120) NOT NULL,
  knowledge_key   VARCHAR(64) NOT NULL COMMENT 'latin: V1, C10…',
  name_ru         VARCHAR(160) NOT NULL,
  requires_text   VARCHAR(255) NULL,
  unlocks_text    VARCHAR(500) NULL,
  sort_order      INT NOT NULL DEFAULT 0,
  at_click        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = уже на щелчке',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_knowledge_key (knowledge_key),
  INDEX idx_knowledge_block (block_num, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Админ: user id=1, пароль 123456 (сменить перед релизом)
UPDATE users
SET role = 'admin',
    password_hash = '$2y$10$rQZ5K5Y5Y5Y5Y5Y5Y5Y5YuKPLACEHOLDER'
WHERE id = 1;
