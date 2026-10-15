# Civaris UI icons v1 — Clay Soft + soft-3D товары

Статус: метрики **Clay Soft** locked; товары — **soft-3D PNG** в стиле куба (2026-09-23).

В UI нет Unicode-эмодзи — метрики SVG, товары PNG (`/assets/icons/sku/{sku_key}.png`).

## Стиль

### Метрики (Clay Soft)
- Плоский SVG, скругления, обводка **1.75px** `#1f3d28`
- Заливка: охра `#c4a574` / cream `#fffcf5` / pine `#2f5d3a` (прозрачность)
- `viewBox="0 0 24 24"` · Vue: `CivIcon`

### Товары (soft-3D toy)
- PNG: матовый пластик / designer-toy, как куб-человек (`cube-s2`)
- `CivIcon` с `sku-key` → img; иначе fallback SVG по `icon_key` / `skuIcon`
- Модель каталога: [`catalog-model-v1.md`](catalog-model-v1.md)
- Синк: `docs/_sync_sku_icons.py` → `public/` + `web/public/`

## Набор

### Метрики
| key | смысл |
|-----|--------|
| coin | деньги / касса |
| workers | работники |
| vacancy | вакансии / без роли |
| population | население |
| credit | кредит |
| firm | компания |

### Товары / склад
| key | SKU / смысл |
|-----|-------------|
| apple | яблоко (+ др. фрукты пока) |
| water | вода |
| pot | горшок |
| clay | глина |
| log | бревно / ствол / кора |
| wheat | пшеница |
| flour | мука |
| egg | яйцо |
| milk | молоко |
| flatbread | лепёшка |
| cheese | сыр |
| stone | камень |
| crate | ящик / бочка / ведро |
| stock | прочее |
