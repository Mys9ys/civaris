/** Фирма → slug файла /assets/icons/firms/{slug}.png */

const MAP = {
  Водосбор: 'vodosbor',
  ГонПром: 'gonprom',
  Казна: 'kazna',
  КамДоб: 'kamdob',
  КорФерма: 'korferma',
  КурФерм: 'kurferm',
  ЛесПром: 'lesprom',
  Дерево: 'lesprom', // legacy alias → ЛесПром
  ОвФерма: 'ovferma',
  Пекарня: 'pekarnya',
  ПрБюро: 'prbyuro',
  ПшАгро: 'pshagro',
  РемеслФабр: 'remeslfabr',
  СтройКом: 'stroykom',
  ТРКом: 'trkom',
  ТрКом: 'trkom', // seed alias
  Харчевня: 'kharchevnya',
  ХлопАгро: 'khlopagro',
  ЧБ: 'chb',
  ШвейФаб: 'shveyfab',
  ЯблПром: 'yablprom',
  Магазин: 'magazin',
  МолокоЗавод: 'molokozavod',
}

/** Активные шаблоны для пикера «Кто делает» (фаза 0). */
const INACTIVE_FIRMS = new Set(['Дерево', 'ТрКом', 'Пекарня', 'МолокоЗавод'])

export const FIRM_CODES = Object.keys(MAP).filter((k) => !INACTIVE_FIRMS.has(k))

export function firmSlug(code) {
  if (!code) return ''
  if (MAP[code]) return MAP[code]
  // мягкий матч по регистру (ТРКом / ТрКом)
  const found = Object.keys(MAP).find((k) => k.toLowerCase() === String(code).toLowerCase())
  return found ? MAP[found] : ''
}

export function firmLogoSrc(code) {
  const slug = firmSlug(code)
  return slug ? `/assets/icons/firms/${slug}.png` : ''
}
