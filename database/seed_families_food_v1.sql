-- Civaris: семейства фрукты + овощи сырые + овощи (готовка) (v1.4)
-- mysql ... --default-character-set=utf8mb4 < database/seed_families_food_v1.sql
USE civaris;
SET NAMES utf8mb4;

ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS food_subtype VARCHAR(32) NULL COMMENT 'fruit|vegetable|vegetable_cook|bakery' AFTER item_type_ru;
ALTER TABLE ref_catalog
  ADD COLUMN IF NOT EXISTS food_subtype_ru VARCHAR(64) NULL AFTER food_subtype;

DELETE FROM ref_catalog WHERE sku_key IN (
  'apple','pear','banana','mandarin','grape','peach',
  'olive','cucumber','tomato','carrot','cabbage','potato','pepper','zucchini',
  'beet','pumpkin','eggplant','corn',
  'water_1l','pot_1l'
) OR category IN ('fruit','vegetable','vegetable_cook','bakery','water','pottery');

INSERT INTO ref_catalog
  (sku_key, name_ru, item_type, item_type_ru, food_subtype, food_subtype_ru, category, category_ru, unit, kcal, notes, sort_order, is_active)
VALUES
-- фрукты (сразу съедобные)
('apple',    'Яблоко',           'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'База калорийной карты', 10, 1),
('pear',     'Груша',            'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'Как яблоко', 20, 1),
('banana',   'Банан',            'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'Как яблоко', 30, 1),
('peach',    'Персик',           'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',    100.000, 'Как яблоко; тёплый пояс', 40, 1),
('mandarin', 'Мандарин',         'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'шт',     50.000, 'Как ½ яблока', 50, 1),
('grape',    'Виноград (ягода)', 'food', 'съедобное/пища', 'fruit', 'фрукты', 'fruit', 'Фрукты', 'ягода',  20.000, '1 яблоко = 5 ягод', 60, 1),
-- овощи сразу съедобные
('cucumber', 'Огурец',           'food', 'съедобное/пища', 'vegetable', 'овощи (сырые)', 'vegetable', 'Овощи сырые', 'шт', 100.000, 'Сразу в пищу; как яблоко', 70, 1),
('zucchini', 'Кабачок',          'food', 'съедобное/пища', 'vegetable', 'овощи (сырые)', 'vegetable', 'Овощи сырые', 'шт', 100.000, 'Сразу в пищу (молодой); как яблоко; вместо картофеля', 80, 1),
('tomato',   'Помидор',          'food', 'съедобное/пища', 'vegetable', 'овощи (сырые)', 'vegetable', 'Овощи сырые', 'шт',  50.000, 'Сразу в пищу; как мандарин', 90, 1),
('carrot',   'Морковь',          'food', 'съедобное/пища', 'vegetable', 'овощи (сырые)', 'vegetable', 'Овощи сырые', 'шт',  50.000, 'Сразу в пищу; как мандарин', 100, 1),
('pepper',   'Перец',            'food', 'съедобное/пища', 'vegetable', 'овощи (сырые)', 'vegetable', 'Овощи сырые', 'шт',  50.000, 'Сразу в пищу; как мандарин', 110, 1),
('olive',    'Оливка',           'food', 'съедобное/пища', 'vegetable', 'овощи (сырые)', 'vegetable', 'Овощи сырые', 'шт',  20.000, 'Сразу в пищу; как виноград; масло позже', 120, 1),
-- овощи, ккал только после готовки (на щелчке сырыми не кормят)
('potato',   'Картофель',        'food', 'съедобное/пища', 'vegetable_cook', 'овощи (готовка)', 'vegetable_cook', 'Овощи готовка', 'шт', 100.000, 'Нужна готовка; порция как яблоко', 130, 1),
('cabbage',  'Капуста',          'food', 'съедобное/пища', 'vegetable_cook', 'овощи (готовка)', 'vegetable_cook', 'Овощи готовка', 'шт', 100.000, 'Нужна готовка; порция как яблоко', 140, 1),
('pumpkin',  'Тыква',            'food', 'съедобное/пища', 'vegetable_cook', 'овощи (готовка)', 'vegetable_cook', 'Овощи готовка', 'шт', 100.000, 'Нужна готовка; порция как яблоко', 150, 1),
('corn',     'Кукуруза',         'food', 'съедобное/пища', 'vegetable_cook', 'овощи (готовка)', 'vegetable_cook', 'Овощи готовка', 'шт', 100.000, 'Нужна готовка (початок/порция); как яблоко', 160, 1),
('beet',     'Свёкла',           'food', 'съедобное/пища', 'vegetable_cook', 'овощи (готовка)', 'vegetable_cook', 'Овощи готовка', 'шт',  50.000, 'Нужна готовка; как мандарин', 170, 1),
('eggplant', 'Баклажан',         'food', 'съедобное/пища', 'vegetable_cook', 'овощи (готовка)', 'vegetable_cook', 'Овощи готовка', 'шт',  50.000, 'Нужна готовка; как мандарин', 180, 1),
-- не еда
('water_1l', 'Вода',             'water',     'вода', NULL, NULL, 'water',   'Вода',       'л',  NULL, 'Норма щелчка: 1 л/сутки', 200, 1),
('pot_1l',   'Горшок 1 л',       'container', 'тара', NULL, NULL, 'pottery', 'Гончарство', 'шт', NULL, 'Тара под воду', 210, 1);

DELETE FROM ref_families WHERE group_key IN ('fruits', 'vegetables', 'vegetables_cook');

INSERT INTO ref_families (group_key, group_name_ru, item_key, item_name_ru, role_note, sort_order, is_active) VALUES
('fruits', 'Плодовые / фрукты', 'grape',    'виноград', 'сразу съедобно; торговля / вино позже', 10, 1),
('fruits', 'Плодовые / фрукты', 'apple',    'яблоко',   'сразу съедобно; база ккал', 20, 1),
('fruits', 'Плодовые / фрукты', 'mandarin', 'мандарин', 'сразу съедобно; тёплый пояс', 30, 1),
('fruits', 'Плодовые / фрукты', 'pear',     'груша',    'сразу съедобно; умеренный', 40, 1),
('fruits', 'Плодовые / фрукты', 'banana',   'банан',    'сразу съедобно; тропики', 50, 1),
('fruits', 'Плодовые / фрукты', 'peach',    'персик',   'сразу съедобно; тёплый пояс', 60, 1),
('vegetables', 'Овощи (сырые)', 'cucumber', 'огурец',   'сразу в пищу; 100 ккал', 10, 1),
('vegetables', 'Овощи (сырые)', 'zucchini', 'кабачок',  'сразу в пищу (молодой); 100 ккал; вместо картофеля', 20, 1),
('vegetables', 'Овощи (сырые)', 'tomato',   'помидор',  'сразу в пищу; 50 ккал', 30, 1),
('vegetables', 'Овощи (сырые)', 'carrot',   'морковь',  'сразу в пищу; 50 ккал', 40, 1),
('vegetables', 'Овощи (сырые)', 'pepper',   'перец',    'сразу в пищу; 50 ккал', 50, 1),
('vegetables', 'Овощи (сырые)', 'olive',    'оливка',   'сразу в пищу; 20 ккал; масло позже', 60, 1),
('vegetables_cook', 'Овощи (готовка)', 'potato',   'картофель', 'ккал после готовки; 100', 10, 1),
('vegetables_cook', 'Овощи (готовка)', 'cabbage',  'капуста',   'ккал после готовки; 100', 20, 1),
('vegetables_cook', 'Овощи (готовка)', 'pumpkin',  'тыква',     'ккал после готовки; 100', 30, 1),
('vegetables_cook', 'Овощи (готовка)', 'corn',     'кукуруза',  'ккал после готовки; 100', 40, 1),
('vegetables_cook', 'Овощи (готовка)', 'beet',     'свёкла',    'ккал после готовки; 50', 50, 1),
('vegetables_cook', 'Овощи (готовка)', 'eggplant', 'баклажан',  'ккал после готовки; 50', 60, 1);
