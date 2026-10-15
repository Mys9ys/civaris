/** SKU → SVG-контур CivIcon (не эмодзи). */

export function skuIcon(sku) {
  const s = String(sku || '').toLowerCase()
  if (s.includes('topor') || s.includes('axe')) return 'axe'
  if (s.includes('molot') || s.includes('hammer')) return 'hammer'
  if (s.includes('lopat') || s.includes('shovel')) return 'shovel'
  if (s.includes('nozh') || s.includes('knife')) return 'knife'
  if (s.includes('steyk') || s.includes('steak')) return 'steak'
  if (s.includes('sok') || s.includes('juice')) return 'juice'
  if (s.includes('banan')) return 'banana'
  if (s.includes('ratsion') || s.includes('ration')) return 'ration'
  if (s.includes('yablok') || s.includes('apple')) return 'apple'
  if (s.includes('vod') || s.includes('water')) return 'water'
  if (s.includes('gorsh') || s.includes('pot')) return 'pot'
  if (s.includes('flakon') || s.includes('chernil') || s.includes('ink')) return 'ink'
  if (s.includes('glin') || s.includes('clay')) return 'clay'
  if (s.includes('dosk') || s.includes('gorbyl') || s.includes('board')) return 'board'
  if (s.includes('vetk') || s.includes('opil') || s.includes('obrez') || s.includes('branch')) return 'branch'
  if (s.includes('brevn') || s.includes('stvol') || s.includes('log') || s.includes('kor')) return 'log'
  if (s.includes('verev') || s.includes('rope')) return 'rope'
  if (s.includes('telezh') || s.includes('cart')) return 'cart'
  if (s.includes('kolod') || s.includes('well')) return 'well'
  if (s.includes('pech') || s.includes('obzhig') || s.includes('oven')) return 'oven'
  if (s.includes('zhernov') || s.includes('mill')) return 'mill'
  if (s.includes('koles') || s.includes('wheel')) return 'wheel'
  if (s.includes('grosbuh') || s.includes('knig') || s.includes('book')) return 'book'
  if (s.includes('bumag') || s.includes('kulek') || s.includes('paper')) return 'paper'
  if (s.includes('krask') || s.includes('paint')) return 'paint'
  if (s.includes('muka') || s.includes('flour')) return 'flour'
  if (s.includes('pshen') || s.includes('wheat')) return 'wheat'
  if (s.includes('yay') || s.includes('egg')) return 'egg'
  if (s.includes('molok') || s.includes('milk')) return 'milk'
  if (s.includes('lepesh') || s.includes('flat')) return 'flatbread'
  if (s.includes('syr') || s.includes('cheese')) return 'cheese'
  if (s.includes('kamen') || s.includes('stone')) return 'stone'
  if (s.includes('yashch') || s.includes('bochk') || s.includes('vedr') || s.includes('crate')) return 'crate'
  if (s.includes('schet') || s.includes('perevoz') || s.includes('service')) return 'service'
  return 'stock'
}

export function extractMark(nameRu) {
  if (!nameRu) return ''
  const m = String(nameRu).match(/(?<![.\d])(\d+(?:[.,]\d+)?)\s*(?:л|кг|шт)?/i)
  if (!m) return ''
  const raw = m[1].replace(',', '.')
  const n = Number(raw)
  if (!Number.isNaN(n) && n === Math.floor(n)) return String(Math.floor(n))
  return raw
}

/** Визуал: монетка у конечного продукта / тары / сырья с ценой магазина. */
export function skuVisual(row = {}) {
  const name = row.icon_key || skuIcon(row.sku_key)
  const mark = row.icon_mark != null && row.icon_mark !== '' ? String(row.icon_mark) : extractMark(row.name_ru)
  const shop =
    row.is_shop === 1 ||
    row.is_shop === true ||
    row.kind === 'good' ||
    row.kind === 'pack' ||
    (row.kind === 'raw' && row.price_shop != null && row.price_shop !== '')
  return {
    name,
    mark,
    shop,
    tintKey: row.sku_key || row.name_ru || name,
  }
}
