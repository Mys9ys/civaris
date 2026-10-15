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
        self::ensureChronicleMessageText($pdo);
        self::ensurePeopleJobs($pdo);
        self::ensureEconomy($pdo);
        self::ensureSettlementPlots($pdo);
        self::ensureSettlementLayoutCanvas($pdo);
        self::ensureProductCatalog($pdo);
    }

    /** Ручные границы холста редактора (не растут от перетаскивания объектов). */
    private static function ensureSettlementLayoutCanvas(PDO $pdo): void
    {
        self::ensureColumn(
            $pdo,
            'settlements',
            'layout_canvas_json',
            "MEDIUMTEXT NULL COMMENT 'map_min/max см для редактора' AFTER population"
        );
    }

    private static function ensureSettlementPlots(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settlement_plots (
              id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              settlement_id      INT UNSIGNED NOT NULL,
              plot_key           VARCHAR(32) NOT NULL,
              kind               VARCHAR(16) NOT NULL,
              title              VARCHAR(128) NOT NULL,
              grid_col           SMALLINT NOT NULL DEFAULT 0,
              grid_row           SMALLINT NOT NULL DEFAULT 0,
              span_cols          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
              span_rows          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
              x_cm               DECIMAL(12,2) NULL COMMENT \'левый край, см от NW\',
              y_cm               DECIMAL(12,2) NULL COMMENT \'верхний край, см от NW\',
              width_cm           DECIMAL(12,2) NULL COMMENT \'габарит по X (восток)\',
              depth_cm           DECIMAL(12,2) NULL COMMENT \'габарит по Y (юг)\',
              building_sku_key   VARCHAR(64) NULL,
              company_code       VARCHAR(32) NULL,
              capacity           SMALLINT UNSIGNED NULL,
              occupied           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              sort_order         INT NOT NULL DEFAULT 0,
              UNIQUE KEY uq_settlement_plot (settlement_id, plot_key),
              INDEX idx_plots_settlement (settlement_id),
              INDEX idx_plots_grid (settlement_id, grid_row, grid_col),
              CONSTRAINT fk_plots_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::ensureColumn($pdo, 'settlement_plots', 'x_cm', "DECIMAL(12,2) NULL COMMENT 'левый край, см от NW' AFTER span_rows");
        self::ensureColumn($pdo, 'settlement_plots', 'y_cm', "DECIMAL(12,2) NULL COMMENT 'верхний край, см от NW' AFTER x_cm");
        self::ensureColumn($pdo, 'settlement_plots', 'width_cm', "DECIMAL(12,2) NULL COMMENT 'габарит по X' AFTER y_cm");
        self::ensureColumn($pdo, 'settlement_plots', 'depth_cm', "DECIMAL(12,2) NULL COMMENT 'габарит по Y' AFTER width_cm");
        self::ensureColumn($pdo, 'settlement_plots', 'rotation_deg', 'SMALLINT NOT NULL DEFAULT 0 AFTER depth_cm');
        self::ensureColumn($pdo, 'settlement_plots', 'flip_x', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER rotation_deg');
        self::ensureColumn($pdo, 'settlement_plots', 'flip_y', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER flip_x');
        self::ensureColumn($pdo, 'settlement_plots', 'is_pinned', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER flip_y');
        self::ensureColumn(
            $pdo,
            'settlement_plots',
            'build_status',
            "VARCHAR(16) NOT NULL DEFAULT 'ready' COMMENT 'ready|building' AFTER is_pinned"
        );
        self::ensureColumn($pdo, 'settlement_plots', 'template_key', 'VARCHAR(64) NULL AFTER build_status');
        self::ensureColumn($pdo, 'settlement_plots', 'floor_plan_json', 'MEDIUMTEXT NULL AFTER template_key');
        // plot_key может быть длиннее для custom firm_*
        try {
            $pdo->exec('ALTER TABLE settlement_plots MODIFY plot_key VARCHAR(64) NOT NULL');
        } catch (\Throwable $e) {
            // уже VARCHAR(64) или нет прав — ок
        }
    }

    private static function ensureProductCatalog(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ref_sku (
              id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              sku_key          VARCHAR(64) NOT NULL,
              name_ru          VARCHAR(128) NOT NULL,
              product_type     VARCHAR(64) NULL,
              product_kind     VARCHAR(64) NULL,
              producer_code    VARCHAR(32) NULL,
              unit             VARCHAR(16) NOT NULL DEFAULT \'шт\',
              sell_channel     VARCHAR(16) NOT NULL DEFAULT \'shop\',
              labor_min        DECIMAL(12,3) NULL,
              thermal_note     VARCHAR(128) NULL,
              tool_name        VARCHAR(128) NULL,
              transport_name   VARCHAR(128) NULL,
              shape            VARCHAR(16) NOT NULL DEFAULT \'none\',
              width_cm         DECIMAL(12,4) NULL,
              length_cm        DECIMAL(12,4) NULL,
              height_cm        DECIMAL(12,4) NULL,
              thickness_cm     DECIMAL(12,4) NULL,
              density          DECIMAL(12,6) NULL,
              vol_cyl_l        DECIMAL(12,4) NULL,
              vol_box_l        DECIMAL(12,4) NULL,
              weight_kg        DECIMAL(12,4) NULL,
              pack_qty         DECIMAL(12,4) NULL,
              cost_seb         DECIMAL(14,4) NULL,
              cost             DECIMAL(14,4) NULL,
              price_internal   DECIMAL(14,4) NULL,
              price_shop       DECIMAL(14,4) NULL,
              kind             VARCHAR(16) NOT NULL DEFAULT \'good\',
              is_tool          TINYINT(1) NOT NULL DEFAULT 0,
              icon_key         VARCHAR(32) NULL,
              prod_month_90    DECIMAL(14,4) NULL,
              notes            TEXT NULL,
              sort_order       INT NOT NULL DEFAULT 0,
              is_active        TINYINT(1) NOT NULL DEFAULT 1,
              UNIQUE KEY uq_ref_sku_key (sku_key),
              INDEX idx_ref_sku_producer (producer_code),
              INDEX idx_ref_sku_type (product_type),
              INDEX idx_ref_sku_channel (sell_channel),
              INDEX idx_ref_sku_kind (kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::ensureSkuColumn($pdo, 'cost', 'DECIMAL(14,4) NULL AFTER cost_seb');
        self::ensureSkuColumn($pdo, 'price_internal', 'DECIMAL(14,4) NULL AFTER cost');
        self::ensureSkuColumn($pdo, 'kind', "VARCHAR(16) NOT NULL DEFAULT 'good' AFTER sell_channel");
        self::ensureSkuColumn($pdo, 'is_tool', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER kind');
        self::ensureSkuColumn($pdo, 'icon_key', 'VARCHAR(32) NULL AFTER is_tool');
        self::ensureSkuColumn($pdo, 'icon_mark', 'VARCHAR(8) NULL AFTER icon_key');
        self::ensureSkuColumn($pdo, 'is_shop', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER icon_mark');
        self::ensureSkuColumn($pdo, 'density', 'DECIMAL(12,6) NULL AFTER thickness_cm');
        self::ensureSkuColumn($pdo, 'vol_cyl_l', 'DECIMAL(12,4) NULL AFTER density');
        self::ensureSkuColumn($pdo, 'vol_box_l', 'DECIMAL(12,4) NULL AFTER vol_cyl_l');
        self::ensureSkuColumn($pdo, 'pack_qty', 'DECIMAL(12,4) NULL AFTER weight_kg');
        self::ensureSkuColumn($pdo, 'contents_qty', 'DECIMAL(12,4) NULL AFTER pack_qty');
        self::ensureSkuColumn($pdo, 'transport_trips_day', 'DECIMAL(12,4) NULL AFTER contents_qty');
        self::ensureSkuColumn($pdo, 'shift_pct', 'DECIMAL(6,2) NULL AFTER labor_min');
        self::ensureSkuColumn($pdo, 'labor_workers', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER shift_pct');
        self::ensureSkuColumn($pdo, 'build_partitions', 'SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER labor_workers');
        self::ensureSkuColumn($pdo, 'build_windows', 'SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER build_partitions');
        self::ensureSkuColumn($pdo, 'build_doors', 'SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER build_windows');
        self::ensureSkuColumn($pdo, 'pair_sku_key', 'VARCHAR(64) NULL AFTER build_doors');
        self::ensureSkuColumn($pdo, 'cost_amort', 'DECIMAL(14,4) NULL AFTER weight_kg');
        self::ensureSkuColumn($pdo, 'cost_transport', 'DECIMAL(14,4) NULL AFTER cost_amort');
        self::ensureSkuColumn($pdo, 'cost_tara', 'DECIMAL(14,4) NULL AFTER cost_transport');
        self::migrateSkuKinds($pdo);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ref_sku_ingredient (
              id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              sku_id               BIGINT UNSIGNED NOT NULL,
              ingredient_sku_key   VARCHAR(64) NULL,
              ingredient_name_ru   VARCHAR(128) NOT NULL,
              qty                  DECIMAL(14,4) NULL,
              unit_weight          DECIMAL(14,4) NULL,
              sort_order           SMALLINT NOT NULL DEFAULT 0,
              INDEX idx_ing_sku (sku_id),
              CONSTRAINT fk_ing_sku FOREIGN KEY (sku_id) REFERENCES ref_sku(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ref_fiscal (
              id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              scope_type            VARCHAR(16) NOT NULL DEFAULT \'global\',
              world_id              INT UNSIGNED NULL,
              title                 VARCHAR(64) NOT NULL DEFAULT \'default\',
              vat_rate              DECIMAL(8,4) NOT NULL DEFAULT 0.0700,
              shop_margin_rate      DECIMAL(8,4) NOT NULL DEFAULT 0.1000,
              producer_margin_rate  DECIMAL(8,4) NOT NULL DEFAULT 0.2000,
              util_rate             DECIMAL(8,4) NOT NULL DEFAULT 0.9000,
              labor_min_hour        DECIMAL(8,2) NOT NULL DEFAULT 50.00,
              labor_min_day         DECIMAL(8,2) NOT NULL DEFAULT 300.00,
              labor_min_month       DECIMAL(8,2) NOT NULL DEFAULT 2700.00,
              UNIQUE KEY uq_fiscal_scope (scope_type, world_id),
              INDEX idx_fiscal_world (world_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::ensureRefFirms($pdo);

        // Посев каталога и фискальных констант (идемпотентно).
        try {
            $catalog = new CatalogService($pdo);
            $catalog->ensureDefaultFiscal();
            $catalog->seedFromJson(false);
            $catalog->seedFirms(false);
            // Бригадные SKU из сида (default 1 не считается «пустым» для mergeFill).
            $pdo->exec(
                "UPDATE ref_sku SET labor_workers = 2
                 WHERE sku_key = 'kolodets_s_vodoy' AND (labor_workers IS NULL OR labor_workers < 2)"
            );
        } catch (\Throwable) {
            // seed-файл может отсутствовать на первом деплое — таблицы уже созданы
        }
    }

    private static function ensureRefFirms(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ref_firm (
              id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code             VARCHAR(32) NOT NULL,
              title            VARCHAR(128) NOT NULL,
              kind             VARCHAR(16) NOT NULL DEFAULT \'firm\',
              workers_default  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              sort_order       INT NOT NULL DEFAULT 0,
              is_active        TINYINT(1) NOT NULL DEFAULT 1,
              notes            TEXT NULL,
              UNIQUE KEY uq_ref_firm_code (code),
              INDEX idx_ref_firm_kind (kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ref_firm_product (
              id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              firm_code   VARCHAR(32) NOT NULL,
              sku_key     VARCHAR(64) NOT NULL,
              qty_day     DECIMAL(14,4) NULL,
              sort_order  SMALLINT NOT NULL DEFAULT 0,
              UNIQUE KEY uq_ref_firm_product (firm_code, sku_key),
              INDEX idx_ref_firm_product_sku (sku_key),
              CONSTRAINT fk_ref_firm_product_firm FOREIGN KEY (firm_code) REFERENCES ref_firm(code)
                ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function ensureSkuColumn(PDO $pdo, string $name, string $ddlAfter): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM ref_sku LIKE " . $pdo->quote($name));
        if ($stmt && $stmt->fetch()) {
            return;
        }
        $pdo->exec('ALTER TABLE ref_sku ADD COLUMN `' . $name . '` ' . $ddlAfter);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $name, string $ddlAfter): void
    {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($name));
        if ($stmt && $stmt->fetch()) {
            return;
        }
        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $ddlAfter);
    }

    /** Разнести сырьё / внутрь; монетка = товар (не инструмент). */
    private static function migrateSkuKinds(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE ref_sku SET kind = 'internal'
             WHERE kind = 'raw'
               AND (
                 name_ru LIKE '% внут'
                 OR name_ru LIKE '% внут.'
                 OR name_ru LIKE '% (внут)%'
                 OR name_ru LIKE '% внутрь'
                 OR sku_key LIKE '%\\_vnut'
                 OR sku_key LIKE '%\\_vnutr%'
               )"
        );
        $pdo->exec(
            "UPDATE ref_sku SET kind = 'tool', is_tool = 1, is_shop = 0, sell_channel = 'capital'
             WHERE COALESCE(is_tool, 0) = 1 AND kind NOT IN ('tool')"
        );
        $pdo->exec(
            "UPDATE ref_sku SET is_tool = 1, is_shop = 0
             WHERE kind = 'tool'"
        );
        $pdo->exec(
            "UPDATE ref_sku SET is_tool = 0
             WHERE kind <> 'tool'"
        );
        $pdo->exec(
            "UPDATE ref_sku SET is_shop = 1
             WHERE kind IN ('good', 'pack')"
        );
        $pdo->exec(
            "UPDATE ref_sku SET is_shop = 1
             WHERE kind = 'raw' AND price_shop IS NOT NULL"
        );
        $pdo->exec(
            "UPDATE ref_sku SET kind = 'project', is_shop = 0, sell_channel = 'service', price_shop = NULL
             WHERE kind = 'service'
               AND (
                 sku_key LIKE 'proekt\\_%'
                 OR sku_key LIKE 'nauchnaya\\_%'
                 OR product_kind LIKE 'Проект%'
                 OR product_kind = 'Научная работа'
               )"
        );
        $pdo->exec(
            "UPDATE ref_sku SET is_shop = 0
             WHERE kind IN ('internal', 'service', 'tool', 'project', 'portion', 'building')
                OR (kind = 'raw' AND price_shop IS NULL)"
        );
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

    private static function ensureChronicleMessageText(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM chronicle LIKE 'message'");
        $col = $stmt ? $stmt->fetch() : false;
        if (!$col) {
            return;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        if (str_contains($type, 'text')) {
            return;
        }
        $pdo->exec('ALTER TABLE chronicle MODIFY COLUMN message TEXT NOT NULL');
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

    private static function ensureEconomy(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS companies (
              id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              world_id         INT UNSIGNED NOT NULL,
              settlement_id    INT UNSIGNED NOT NULL,
              code             VARCHAR(32) NOT NULL,
              title            VARCHAR(128) NOT NULL,
              kind             VARCHAR(16) NOT NULL DEFAULT \'firm\',
              template_code    VARCHAR(32) NULL,
              workers          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              credit_principal DECIMAL(14,2) NOT NULL DEFAULT 0,
              credit_rate      DECIMAL(8,4) NOT NULL DEFAULT 0,
              has_grossbook    TINYINT(1) NOT NULL DEFAULT 0,
              created_day      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
              created_month    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
              created_year     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
              UNIQUE KEY uq_companies_world_code (world_id, code),
              INDEX idx_companies_settlement (settlement_id),
              INDEX idx_companies_template (template_code),
              CONSTRAINT fk_companies_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
              CONSTRAINT fk_companies_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::ensureColumn($pdo, 'companies', 'template_code', 'VARCHAR(32) NULL AFTER kind');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS company_lines (
              id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              company_id  BIGINT UNSIGNED NOT NULL,
              sku_key     VARCHAR(64) NOT NULL,
              qty_day     DECIMAL(14,4) NULL,
              qty_fact    DECIMAL(14,4) NULL,
              sort_order  SMALLINT NOT NULL DEFAULT 0,
              UNIQUE KEY uq_company_lines (company_id, sku_key),
              INDEX idx_company_lines_sku (sku_key),
              CONSTRAINT fk_company_lines_company FOREIGN KEY (company_id) REFERENCES companies(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::ensureColumn($pdo, 'company_lines', 'qty_fact', 'DECIMAL(14,4) NULL AFTER qty_day');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS wallets (
              id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              world_id      INT UNSIGNED NOT NULL,
              settlement_id INT UNSIGNED NULL,
              owner_type    VARCHAR(16) NOT NULL,
              owner_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
              balance       DECIMAL(14,2) NOT NULL DEFAULT 0,
              UNIQUE KEY uq_wallets_owner (world_id, owner_type, owner_id),
              INDEX idx_wallets_settlement (settlement_id),
              CONSTRAINT fk_wallets_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ledger_entries (
              id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              world_id       INT UNSIGNED NOT NULL,
              settlement_id  INT UNSIGNED NULL,
              day            SMALLINT UNSIGNED NOT NULL,
              month          SMALLINT UNSIGNED NOT NULL,
              year           SMALLINT UNSIGNED NOT NULL,
              kind           VARCHAR(32) NOT NULL,
              amount         DECIMAL(14,2) NOT NULL,
              from_wallet_id BIGINT UNSIGNED NULL,
              to_wallet_id   BIGINT UNSIGNED NULL,
              company_id     BIGINT UNSIGNED NULL,
              note           VARCHAR(255) NOT NULL DEFAULT \'\',
              created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_ledger_world_day (world_id, year, month, day),
              CONSTRAINT fk_ledger_world FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
