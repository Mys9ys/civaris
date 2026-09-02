-- Civaris: каталог + карта калорий (канон v1.2)
-- Запуск: mysql ... --default-character-set=utf8mb4 < database/seed_calories_v1.sql
USE civaris;
SET NAMES utf8mb4;

ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS kcal DECIMAL(12,3) NULL COMMENT 'ккал за 1 unit; NULL = ещё не задано' AFTER unit;

ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS item_type VARCHAR(32) NOT NULL DEFAULT 'item' COMMENT 'food|semi|good|item|container|water' AFTER name_ru;

ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS item_type_ru VARCHAR(64) NULL COMMENT 'русская подпись типа' AFTER item_type;

ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS food_subtype VARCHAR(32) NULL COMMENT 'fruit|vegetable|bakery; только для food' AFTER item_type_ru;

ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS food_subtype_ru VARCHAR(64) NULL COMMENT 'фрукты|овощи|выпечка' AFTER food_subtype;

DELETE FROM ref_catalog WHERE category IN ('fruit','vegetable','bakery','water','pottery')
  OR sku_key IN (
    'apple','pear','banana','mandarin','grape',
    'olive','cucumber','tomato','carrot','cabbage',
    'water_1l','pot_1l'
  );

INSERT INTO ref_catalog
  (sku_key, name_ru, item_type, item_type_ru, food_subtype, food_subtype_ru, category, category_ru, unit, kcal, notes, sort_order, is_active)
VALUES
-- фрукты
('apple',    'Яблоко',           'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'База калорийной карты', 10, 1),
('pear',     'Груша',            'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'Вес/ккал как у яблока', 20, 1),
('banana',   'Банан',            'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'Вес/ккал как у яблока', 30, 1),
('mandarin', 'Мандарин',         'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',     50.000, '1 яблоко = 2 мандарина', 40, 1),
('grape',    'Виноград (ягода)', 'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'ягода',  20.000, '1 яблоко = 5 ягод', 50, 1),
-- овощи
('cucumber', 'Огурец',           'food', 'съедобное/пища', 'vegetable', 'овощи', 'vegetable', 'Овощи', 'шт', 100.000, 'Размер/ккал как у яблока', 60, 1),
('tomato',   'Помидор',          'food', 'съедобное/пища', 'vegetable', 'овощи', 'vegetable', 'Овощи', 'шт',  50.000, 'Размер/ккал как у мандарина', 70, 1),
('olive',    'Оливка',           'food', 'съедобное/пища', 'vegetable', 'овощи', 'vegetable', 'Овощи', 'шт',  20.000, 'Размер/ккал как у винограда', 80, 1),
('carrot',   'Морковь',          'food', 'съедобное/пища', 'vegetable', 'овощи', 'vegetable', 'Овощи', 'шт',  50.000, 'Как мандарин; огородный старт', 90, 1),
('cabbage',  'Капуста',          'food', 'съедобное/пища', 'vegetable', 'овощи', 'vegetable', 'Овощи', 'шт', 100.000, 'Как яблоко (небольшой кочан)', 100, 1),
-- не еда
('water_1l', 'Вода',             'water',     'вода', NULL, NULL, 'water',   'Вода',       'л',  NULL, 'Норма щелчка: 1 л/сутки', 200, 1),
('pot_1l',   'Горшок 1 л',       'container', 'тара', NULL, NULL, 'pottery', 'Гончарство', 'шт', NULL, 'Тара под воду — не еда', 210, 1);

DELETE FROM ref_click_needs;
INSERT INTO ref_click_needs (need_key, name_ru, period, amount, unit, how_closed, notes, sort_order, is_active) VALUES
('kcal_day', 'Калории', 'day', 300.000, 'ккал', 'любая смесь еды с карты калорий', 'БЖУ не используем. База на щелчке = 300. Гены позже могут повышать норму.', 10, 1),
('water_day', 'Вода', 'day', 1.000, 'л', 'горшок 1 л', 'Неделя 4 дня = 4 л', 20, 1),
('housing_dorm', 'Общежитие', 'once', 20.000, 'мест', 'здание поселения', 'Все 20 на старте в одном общежитии', 30, 1),
('energy_rule', 'Правило энергии', 'day', 1.000, 'флаг', 'полная энергия только если ккал+вода закрыты', 'Недобор ккал → штраф к выработке. Норма ккал может расти с генами.', 40, 1);
