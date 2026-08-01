-- Civaris phase 0 schema (MariaDB / MySQL 8+)
CREATE DATABASE IF NOT EXISTS civaris
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE civaris;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NULL,
  login         VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  vk_id         BIGINT UNSIGNED NULL UNIQUE,
  role          ENUM('player','admin') NOT NULL DEFAULT 'player',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worlds (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  population_cap  INT UNSIGNED NOT NULL DEFAULT 500,
  population      INT UNSIGNED NOT NULL DEFAULT 0,
  day             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  month           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  year            INT NOT NULL DEFAULT 1,
  status          ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_worlds_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chronicle (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id   INT UNSIGNED NOT NULL,
  day        SMALLINT UNSIGNED NOT NULL,
  month      TINYINT UNSIGNED NOT NULL,
  year       INT NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  message    VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_chronicle_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  INDEX idx_chronicle_world_time (world_id, year, month, day, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заготовка под фазу 1 (пока пустые, миграции нарастим)
CREATE TABLE IF NOT EXISTS settlements (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  world_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  kind       ENUM('polis','city','village') NOT NULL DEFAULT 'polis',
  is_capital TINYINT(1) NOT NULL DEFAULT 1,
  pos_x      INT NOT NULL DEFAULT 0,
  pos_y      INT NOT NULL DEFAULT 0,
  population INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_settlements_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
  INDEX idx_settlements_world (world_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
