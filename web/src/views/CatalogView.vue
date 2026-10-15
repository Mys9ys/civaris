<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '../api'
import CivIcon from '../components/CivIcon.vue'
import FirmPick from '../components/FirmPick.vue'
import KindPick from '../components/KindPick.vue'
import SkuPick from '../components/SkuPick.vue'
import UnitPick from '../components/UnitPick.vue'
import RefCrudPanel from '../components/RefCrudPanel.vue'
import { FIRM_CODES, firmLogoSrc } from '../icons/firmLogo'
import { skuIcon, skuVisual } from '../icons/skuIcon'
import {
  CUBE_HEIGHT_CM,
  calcBuilding,
  buildingToIngredients,
  buildingSchemeText,
} from '../lib/buildingCalc'

const user = ref(null)
const error = ref('')
const info = ref('')
const busy = ref(false)
const tab = ref('skus')
const items = ref([])
const q = ref('')
const producer = ref('')
const kindFilter = ref('')
const priceFilter = ref('') // '' | 'has' | 'none'
const priceMin = ref(null)
const priceMax = ref(null)
const sortKey = ref('kind') // name | kind | producer | price
const sortDir = ref('asc')
const editId = ref(null)
const formOpen = ref(false)
const recalcNote = ref('')

/** Вкладка «Постройки»: пары проект+здание. */
const buildRows = ref([])
const buildFormOpen = ref(false)
const buildEditKey = ref('')
const buildForm = reactive({
  name_ru: '',
  sku_key: '',
  project_sku_key: '',
  width_cm: 300,
  length_cm: 500,
  height_cm: 105,
  build_partitions: 1,
  build_windows: 2,
  build_doors: 2,
  builder_code: 'СтройКом',
  bureau_code: 'ПрБюро',
})

const buildPreview = computed(() =>
  calcBuilding({
    width_cm: buildForm.width_cm,
    length_cm: buildForm.length_cm,
    height_cm: buildForm.height_cm,
    partitions: buildForm.build_partitions,
    windows: buildForm.build_windows,
    doors: buildForm.build_doors,
  })
)

async function loadBuildings() {
  const res = await api.catalogBuildings()
  buildRows.value = res.items || []
}

async function openBuildsTab() {
  tab.value = 'builds'
  busy.value = true
  error.value = ''
  try {
    await loadBuildings()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function startNewBuild() {
  buildEditKey.value = ''
  Object.assign(buildForm, {
    name_ru: '',
    sku_key: '',
    project_sku_key: '',
    width_cm: 300,
    length_cm: 500,
    height_cm: 105,
    build_partitions: 1,
    build_windows: 2,
    build_doors: 2,
    builder_code: 'СтройКом',
    bureau_code: 'ПрБюро',
  })
  buildFormOpen.value = true
}

function closeBuildForm() {
  buildFormOpen.value = false
  buildEditKey.value = ''
}

function editBuildRow(row) {
  const b = row.building
  buildEditKey.value = b.sku_key
  Object.assign(buildForm, {
    name_ru: b.name_ru || '',
    sku_key: b.sku_key || '',
    project_sku_key: row.project?.sku_key || b.pair_sku_key || '',
    width_cm: Number(b.width_cm) || 300,
    length_cm: Number(b.length_cm) || 500,
    height_cm: Number(b.height_cm) || 105,
    build_partitions: Number(b.build_partitions ?? 1),
    build_windows: Number(b.build_windows ?? 2),
    build_doors: Number(b.build_doors ?? 2),
    builder_code: b.producer_code || 'СтройКом',
    bureau_code: row.project?.producer_code || 'ПрБюро',
  })
  buildFormOpen.value = true
}

async function saveBuildingPair() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    if (!buildForm.name_ru.trim()) throw new Error('Укажите название')
    const body = {
      name_ru: buildForm.name_ru.trim(),
      sku_key: (buildForm.sku_key || slugFromName(buildForm.name_ru)).trim(),
      project_sku_key: (buildForm.project_sku_key || '').trim() || undefined,
      width_cm: Number(buildForm.width_cm),
      length_cm: Number(buildForm.length_cm),
      height_cm: Number(buildForm.height_cm),
      build_partitions: Number(buildForm.build_partitions) || 0,
      build_windows: Number(buildForm.build_windows) || 0,
      build_doors: Number(buildForm.build_doors) || 0,
      builder_code: buildForm.builder_code || 'СтройКом',
      bureau_code: buildForm.bureau_code || 'ПрБюро',
    }
    const res = await api.catalogBuildingSave(body)
    await loadBuildings()
    await loadSkus()
    try {
      await loadFirms()
    } catch {
      /* фирмы опционально */
    }
    buildFormOpen.value = false
    info.value = `Пара: ${res.building?.sku_key} + ${res.project?.sku_key} (цены ${formatPrice(res.building?.price_internal)} / ${formatPrice(res.project?.price_internal)})`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

/** Справочник плотности (г/см³). */
const densityItems = ref([])
const densityPick = ref('')
/** Пока false — sku_key авто из названия. */
const skuKeyLocked = ref(false)
/** Единицы, добавленные вручную в этой сессии. */
const extraUnits = ref([])

/** ФОТ / мес на 1 работника (канон щелчка). */
const FOT_MONTH = 100

const KIND_ORDER = { building: 0, project: 1, service: 2, tool: 3, internal: 4, portion: 5, raw: 6, pack: 7, good: 8 }

const KINDS = [
  { key: 'raw', ru: 'сырьё', icon: 'kind-raw' },
  { key: 'internal', ru: 'внутрь', icon: 'kind-internal' },
  { key: 'portion', ru: 'порция', icon: 'kind-portion' },
  { key: 'pack', ru: 'тара', icon: 'kind-pack' },
  { key: 'tool', ru: 'инструмент', icon: 'kind-tool' },
  { key: 'good', ru: 'конечный продукт', icon: 'kind-good' },
  { key: 'building', ru: 'здание', icon: 'kind-building' },
  { key: 'project', ru: 'проект / наука', icon: 'kind-project' },
  { key: 'service', ru: 'услуга', icon: 'kind-service' },
]

const SHAPES = [
  { key: 'none', ru: 'нет' },
  { key: 'box', ru: 'прямоугольник' },
  { key: 'cylinder', ru: 'цилиндр' },
  { key: 'other', ru: 'другое' },
]

const shapeOpen = ref(false)
const shapeDraft = reactive({
  length_cm: null,
  width_cm: null,
  height_cm: null,
  diameter_cm: null,
  density: null,
})

/** Объём формы, см³ (только прямоугольник / цилиндр). */
function shapeVolumeCm3(shape, d) {
  if (shape === 'cylinder') {
    const dia = Number(d.diameter_cm)
    const h = Number(d.height_cm)
    if (!(dia > 0) || !(h > 0)) return null
    return Math.PI * (dia / 2) ** 2 * h
  }
  if (shape === 'box') {
    const l = Number(d.length_cm)
    const w = Number(d.width_cm)
    const h = Number(d.height_cm)
    if (!(l > 0) || !(w > 0) || !(h > 0)) return null
    return l * w * h
  }
  return null
}

/** г/см³ × см³ → кг. */
function weightFromShape(shape, d) {
  const vol = shapeVolumeCm3(shape, d)
  const rho = Number(d.density)
  if (vol == null || !(rho > 0)) return null
  return ceil2((vol * rho) / 1000)
}

const shapeDraftVolumeL = computed(() => {
  const vol = shapeVolumeCm3(form.shape, shapeDraft)
  return vol == null ? null : ceil2(vol / 1000)
})

const shapeDraftWeightKg = computed(() => weightFromShape(form.shape, shapeDraft))

/** Калькулятор амортизации инструмента. */
const amortDraft = reactive({
  tool_sku_key: '',
  life_uses: 1000,
})

/** Калькулятор тары / транспорта. */
const taraDraft = reactive({
  pack_sku_key: '',
  transport_sku_key: '',
})

/** Расщепление пачки/бочки магазина → расходная единица. */
const unpackDraft = reactive({
  pack_sku_key: '',
  contents_qty: null,
  use_shop: true,
})

/** Цена выбранного транспорта (рейс / ед. услуги). */
const transportTripPrice = computed(() => {
  const tr = skuByKey(taraDraft.transport_sku_key)
  return ingredientUnitCost(tr)
})

/** Доля смены 0…1; пусто = вся смена. */
function shiftShareOf(row) {
  const v = row?.shift_pct
  if (v === null || v === undefined || v === '') return 1
  const n = Number(v)
  if (!Number.isFinite(n) || n < 0) return 1
  return Math.min(n, 100) / 100
}

/** Сколько человек на операции (колодец, научная работа…). */
function laborWorkersOf(row) {
  const n = Number(row?.labor_workers)
  if (!Number.isFinite(n) || n < 1) return 1
  return Math.floor(n)
}

const laborCostPreview = computed(() => {
  const labor = Number(form.labor_min)
  if (!(labor > 0)) return null
  const minMonth = Number(fiscalForm.labor_min_month)
  const util = Number(fiscalForm.util_rate)
  const monthMin = Number.isFinite(minMonth) && minMonth > 0 ? minMonth : 2700
  const utilR = Number.isFinite(util) && util > 0 ? util : 0.9
  const workers = laborWorkersOf(form)
  const prod90 = (monthMin / labor) * utilR
  return prod90 > 0 ? ceil2((FOT_MONTH * workers * shiftShareOf(form)) / prod90) : null
})

/** Эффективная доля смены с учётом бригады (для подсказки). */
const crewShiftHint = computed(() => {
  const labor = Number(form.labor_min)
  const dayMin = Number(fiscalForm.labor_min_day)
  const workers = laborWorkersOf(form)
  if (!(labor > 0) || !(dayMin > 0)) return null
  const pct = Math.round(((labor * workers) / dayMin) * 1000) / 10
  return { workers, pct }
})

const buildingPreview = computed(() => {
  if (form.kind !== 'building') return null
  return calcBuilding({
    width_cm: form.width_cm,
    length_cm: form.length_cm,
    height_cm: form.height_cm,
    partitions: form.build_partitions,
    windows: form.build_windows,
    doors: form.build_doors,
  })
})

function applyBuildingScheme() {
  error.value = ''
  info.value = ''
  const calc = calcBuilding({
    width_cm: form.width_cm,
    length_cm: form.length_cm,
    height_cm: form.height_cm,
    partitions: form.build_partitions,
    windows: form.build_windows,
    doors: form.build_doors,
  })
  if (!calc.ok) {
    error.value = calc.error || 'Не удалось посчитать схему'
    return
  }
  form.shape = 'box'
  form.unit = form.unit || 'Шт.'
  form.is_shop = 0
  form.labor_min = calc.labor_min
  form.labor_workers = calc.labor_workers
  form.shift_pct = 100
  form.ingredients = buildingToIngredients(calc)
  form.notes = [buildingSchemeText(calc), form.notes && !String(form.notes).includes('Сруб ') ? form.notes : '']
    .filter(Boolean)
    .join('\n\n')
  recalcPrices()
  info.value = 'Схема здания → сырьё и цены (не забудьте Сохранить)'
}

/** Выработка ед./день по труду (как в пересчёте цен). */
function prodPerDay() {
  const labor = Number(form.labor_min)
  const dayMin = Number(fiscalForm.labor_min_day)
  const util = Number(fiscalForm.util_rate)
  if (!(labor > 0) || !(dayMin > 0)) return null
  const utilR = Number.isFinite(util) && util > 0 ? util : 0.9
  return (dayMin / labor) * utilR
}

function emptyIng() {
  return { ingredient_name_ru: '', ingredient_sku_key: '', qty: null, unit_weight: null, sort_order: 0 }
}

function emptyDensity() {
  return { key: '', name_ru: '', density: null, product_kinds: '', name_match: '', notes: '' }
}

function densityToForm(row) {
  return {
    key: row.key || '',
    name_ru: row.name_ru || '',
    density: row.density,
    product_kinds: Array.isArray(row.product_kinds) ? row.product_kinds.join(', ') : '',
    name_match: Array.isArray(row.name_match) ? row.name_match.join(', ') : '',
    notes: row.notes || '',
  }
}

function densityFromForm(row) {
  const split = (s) =>
    String(s || '')
      .split(/[,;]+/)
      .map((x) => x.trim())
      .filter(Boolean)
  return {
    key: String(row.key || '').trim(),
    name_ru: String(row.name_ru || '').trim(),
    density: row.density,
    product_kinds: split(row.product_kinds),
    name_match: split(row.name_match),
    notes: String(row.notes || '').trim(),
  }
}

const densityForms = ref([])

/** Локальный матч справочника: product_kind, затем подстрока названия. */
function suggestDensityLocal(nameRu, productKind) {
  const name = String(nameRu || '').toLowerCase()
  const kind = String(productKind || '').trim().toLowerCase()
  const list = densityItems.value
  if (kind) {
    for (const row of list) {
      if ((row.product_kinds || []).some((k) => String(k).toLowerCase() === kind)) return row
    }
  }
  if (!name) return null
  for (const row of list) {
    for (const m of row.name_match || []) {
      const needle = String(m).toLowerCase().trim()
      if (needle && name.includes(needle)) return row
    }
  }
  return null
}

function applyDensityPick() {
  const row = densityItems.value.find((r) => r.key === densityPick.value)
  if (!row || row.density == null) return
  shapeDraft.density = ceil2(row.density)
}

function applySuggestedDensity() {
  const row = suggestDensityLocal(form.name_ru, form.product_kind)
  if (!row || row.density == null) return
  shapeDraft.density = ceil2(row.density)
  densityPick.value = row.key
}

function emptyForm() {
  return {
    sku_key: '',
    name_ru: '',
    kind: 'good',
    product_type: '',
    product_kind: '',
    producer_code: '',
    unit: 'шт',
    is_tool: 0,
    icon_key: '',
    icon_mark: '',
    is_shop: 0,
    labor_min: null,
    shift_pct: 100,
    labor_workers: 1,
    build_partitions: 1,
    build_windows: 2,
    build_doors: 2,
    thermal_note: '',
    tool_name: '',
    transport_name: '',
    shape: 'none',
    width_cm: null,
    length_cm: null,
    height_cm: null,
    density: null,
    weight_kg: null,
    cost_amort: null,
    cost_transport: null,
    cost_tara: null,
    pack_qty: null,
    contents_qty: null,
    transport_trips_day: null,
    cost: null,
    price_internal: null,
    price_shop: null,
    notes: '',
    sort_order: 0,
    is_active: 1,
    ingredients: [emptyIng()],
  }
}

const form = reactive(emptyForm())

const NUM_FIELDS = [
  'labor_min', 'shift_pct', 'labor_workers', 'build_partitions', 'build_windows', 'build_doors',
  'width_cm', 'length_cm', 'height_cm', 'density', 'weight_kg', 'pack_qty',
  'contents_qty', 'transport_trips_day',
  'cost_amort', 'cost_transport', 'cost_tara', 'cost', 'price_internal', 'price_shop',
]

const fiscalForm = reactive({
  scope_type: 'global',
  world_id: null,
  title: 'Канон старта',
  vat_rate: 0.07,
  shop_margin_rate: 0.1,
  producer_margin_rate: 0.2,
  util_rate: 0.9,
  labor_min_hour: 50,
  labor_min_day: 300,
  labor_min_month: 2700,
})

const producers = computed(() => {
  const set = new Set(FIRM_CODES)
  for (const row of items.value) {
    if (row.producer_code) set.add(row.producer_code)
  }
  set.delete('Дерево')
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'))
})

const unitOptions = computed(() => {
  const set = new Set(extraUnits.value)
  for (const row of items.value) {
    if (row.unit) set.add(String(row.unit).trim())
  }
  if (form.unit) set.add(String(form.unit).trim())
  return [...set].filter(Boolean).sort((a, b) => a.localeCompare(b, 'ru'))
})

const filteredItems = computed(() => {
  let rows = items.value

  const needle = q.value.trim().toLowerCase()
  if (needle) {
    rows = rows.filter((r) => {
      const name = String(r.name_ru || '').toLowerCase()
      const key = String(r.sku_key || '').toLowerCase()
      return name.includes(needle) || key.includes(needle)
    })
  }

  if (producer.value) {
    rows = rows.filter((r) => r.producer_code === producer.value)
  }

  if (kindFilter.value) {
    rows = rows.filter((r) => r.kind === kindFilter.value)
  }

  if (priceFilter.value === 'has') {
    rows = rows.filter((r) => rowPriceNum(r) != null)
  } else if (priceFilter.value === 'none') {
    rows = rows.filter((r) => rowPriceNum(r) == null)
  }

  const min = priceMin.value
  const max = priceMax.value
  if (min != null && min !== '' && !Number.isNaN(Number(min))) {
    rows = rows.filter((r) => {
      const p = rowPriceNum(r)
      return p != null && p >= Number(min)
    })
  }
  if (max != null && max !== '' && !Number.isNaN(Number(max))) {
    rows = rows.filter((r) => {
      const p = rowPriceNum(r)
      return p != null && p <= Number(max)
    })
  }

  const dir = sortDir.value === 'desc' ? -1 : 1
  rows = [...rows].sort((a, b) => {
    let cmp = 0
    if (sortKey.value === 'kind') {
      cmp = (KIND_ORDER[a.kind] ?? 9) - (KIND_ORDER[b.kind] ?? 9)
      if (cmp === 0) cmp = String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru')
    } else if (sortKey.value === 'price') {
      const pa = rowPriceNum(a)
      const pb = rowPriceNum(b)
      if (pa == null && pb == null) cmp = 0
      else if (pa == null) cmp = 1
      else if (pb == null) cmp = -1
      else cmp = pa - pb
    } else if (sortKey.value === 'producer') {
      cmp = String(a.producer_code || '').localeCompare(String(b.producer_code || ''), 'ru')
    } else {
      cmp = String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru')
    }
    return cmp * dir
  })
  return rows
})

function rowPriceNum(row) {
  let raw
  if (row.kind === 'service' || row.kind === 'project' || row.kind === 'building') raw = row.price_internal ?? row.cost
  else if (row.kind === 'internal' || row.kind === 'portion') raw = row.cost ?? row.price_internal
  else if (row.kind === 'raw') raw = row.price_shop ?? row.cost ?? row.price_internal
  else raw = row.price_shop ?? row.price_internal ?? row.cost
  if (raw === null || raw === undefined || raw === '') return null
  return ceil2(raw)
}

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = key === 'price' ? 'asc' : 'asc'
  }
}

function sortMark(key) {
  if (sortKey.value !== key) return ''
  return sortDir.value === 'asc' ? ' ↑' : ' ↓'
}

/** Кандидаты в рецепт: все активные SKU, кроме текущего. */
const ingredientOptions = computed(() => {
  const cur = form.sku_key
  return items.value
    .filter((r) => r.sku_key && r.sku_key !== cur)
    .slice()
    .sort((a, b) => String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru'))
})

/** Инструменты / капитал для амортизации. */
const toolOptions = computed(() =>
  items.value
    .filter((r) => r.kind === 'tool' || r.is_tool === 1 || r.is_tool === true)
    .slice()
    .sort((a, b) => String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru'))
)

/** SKU тары для выбора в поле тары (опционально подставляют цену). */
const packOptions = computed(() =>
  items.value
    .filter((r) => r.kind === 'pack' || /кулек|бочк|ящик|ведр|упаков|тар|горш/i.test(r.name_ru || ''))
    .slice()
    .sort((a, b) => String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru'))
)

/** Пачки / бочки магазина для расщепления на расходную единицу. */
const unpackPackOptions = computed(() =>
  items.value
    .filter(
      (r) =>
        r.kind === 'good' ||
        r.kind === 'pack' ||
        r.kind === 'raw' ||
        Number(r.contents_qty) > 0 ||
        /\d+\s*(лист|шт|л\b)|бочк|пачк|десятк|уп\./i.test(r.name_ru || '')
    )
    .slice()
    .sort((a, b) => String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru'))
)

/** Услуги / средства перевозки для поля транспорта. */
const transportOptions = computed(() =>
  items.value
    .filter(
      (r) =>
        r.kind === 'service' ||
        /перевозк|тележк|транспорт|трком/i.test(`${r.name_ru || ''} ${r.producer_code || ''}`)
    )
    .slice()
    .sort((a, b) => String(a.name_ru || '').localeCompare(String(b.name_ru || ''), 'ru'))
)

function skuByKey(key) {
  if (!key) return null
  return items.value.find((r) => r.sku_key === key) || null
}

const PRICE_SRC_RU = { cost: 'себест', price_internal: 'внутр.', price_shop: 'маг.' }

/** Цена единицы ингредиента для калькуляции: cost → внутрь → магазин. */
function ingredientPrice(row) {
  if (!row) return null
  // Закуп в магазине / готовый товар: сначала розница, иначе себест.
  // «порция» — расход из пачки: всегда себест. единицы.
  const shopFirst =
    row.kind !== 'portion' &&
    row.kind !== 'internal' &&
    row.kind !== 'tool' &&
    row.kind !== 'service' &&
    row.kind !== 'project' &&
    row.kind !== 'building' &&
    (Number(row.is_shop) === 1 ||
      row.kind === 'good' ||
      row.kind === 'pack' ||
      (row.kind === 'raw' && row.price_shop != null && row.price_shop !== ''))
  const order = shopFirst
    ? ['price_shop', 'cost', 'price_internal']
    : ['cost', 'price_internal', 'price_shop']
  for (const k of order) {
    const v = row[k]
    if (v !== null && v !== undefined && v !== '') {
      const n = ceil2(v)
      if (n != null) return { price: n, src: k }
    }
  }
  return null
}

function ingredientUnitCost(row) {
  return ingredientPrice(row)?.price ?? null
}

/** Сводка по строке рецепта для подсказки под ней. */
function ingInfo(ing) {
  const row = skuByKey(ing.ingredient_sku_key)
  if (!row) return null
  const qty = Number(ing.qty)
  const hasQty = Number.isFinite(qty) && qty > 0
  const p = ingredientPrice(row)
  const w = row.weight_kg != null && row.weight_kg !== '' ? Number(row.weight_kg) : null
  return {
    unit: row.unit || '',
    price: p?.price ?? null,
    src: p ? PRICE_SRC_RU[p.src] : null,
    sum: p && hasQty ? ceil2(p.price * qty) : null,
    weight: w != null && hasQty ? ceil2(w * qty) : null,
    unitWeight: w != null ? ceil2(w) : null,
    firm: row.producer_code || '',
    kind: kindRu(row.kind),
  }
}

const ingTotals = computed(() => {
  let sum = 0
  let weight = 0
  let hasSum = false
  let hasWeight = false
  for (const ing of form.ingredients) {
    const inf = ingInfo(ing)
    if (!inf) continue
    if (inf.sum != null) {
      sum += inf.sum
      hasSum = true
    }
    if (inf.weight != null) {
      weight += inf.weight
      hasWeight = true
    }
  }
  return { sum: hasSum ? ceil2(sum) : null, weight: hasWeight ? ceil2(weight) : null }
})

/** Вверх до 0,01; сдвиг 1e6 гасит хвосты float (0,07·100 = 7,0000001). */
function ceil2(n) {
  if (n === null || n === undefined || n === '') return null
  const x = Number(n)
  if (!Number.isFinite(x)) return null
  return Math.ceil(Math.round(x * 1e6) / 1e4) / 100
}

/** Кол-во в рецепте: до 4 знаков (1/60 ≈ 0,0167). */
function qtyPrec(n) {
  if (n === null || n === undefined || n === '') return null
  const x = Number(n)
  if (!Number.isFinite(x)) return null
  return Math.round(x * 1e4) / 1e4
}

/**
 * Канон Sheet/clay: себест → ×(1+маржа произв) → ×(1+НДС) → ×(1+маржа маг).
 * Труд/ед ≈ ФОТ / произв90, произв90 = (мин_мес / труд_мин) × util.
 */
function recalcPrices() {
  error.value = ''
  info.value = ''
  recalcNote.value = ''

  const vat = Number(fiscalForm.vat_rate)
  const shopM = Number(fiscalForm.shop_margin_rate)
  const prodM = Number(fiscalForm.producer_margin_rate)
  const util = Number(fiscalForm.util_rate)
  const minMonth = Number(fiscalForm.labor_min_month)

  const vatR = Number.isFinite(vat) ? vat : 0.07
  const shopR = Number.isFinite(shopM) ? shopM : 0.1
  const prodR = Number.isFinite(prodM) ? prodM : 0.2
  const utilR = Number.isFinite(util) && util > 0 ? util : 0.9
  const monthMin = Number.isFinite(minMonth) && minMonth > 0 ? minMonth : 2700

  let ingCost = 0
  const missing = []
  let usedIngs = 0
  for (const ing of form.ingredients) {
    if (!ing.ingredient_sku_key) continue
    const qty = Number(ing.qty)
    if (!qty || Number.isNaN(qty)) continue
    const row = skuByKey(ing.ingredient_sku_key)
    const p = ingredientUnitCost(row)
    if (p == null) {
      missing.push(ing.ingredient_name_ru || ing.ingredient_sku_key)
      continue
    }
    ingCost += p * qty
    usedIngs += 1
  }

  const labor = Number(form.labor_min)
  const shiftShare = shiftShareOf(form)
  const workers = laborWorkersOf(form)
  let laborCost = 0
  let prod90 = null
  if (labor > 0 && !Number.isNaN(labor)) {
    prod90 = (monthMin / labor) * utilR
    if (prod90 > 0) laborCost = (FOT_MONTH * workers * shiftShare) / prod90
  }

  const amort = Number(form.cost_amort) || 0
  const transport = Number(form.cost_transport) || 0
  const tara = Number(form.cost_tara) || 0

  if (usedIngs === 0 && !(labor > 0) && !(amort || transport || tara)) {
    error.value = 'Нечего считать: нужны ингредиенты, труд и/или аморт/транспорт/тара'
    return
  }

  const cost = ceil2(ingCost + laborCost + amort + transport + tara)
  const withProfit = ceil2(cost * (1 + prodR))
  const withVat = ceil2(withProfit * (1 + vatR))
  const shop = ceil2(withProfit * (1 + vatR) * (1 + shopR))

  form.cost = cost
  // внутрь / порция — только себест.
  // инструмент / проект·наука: себест → маржа произв. → НДС (без магазина).
  // услуга: себест → маржа произв. (пока без НДС).
  // сырьё / конечный / тара: + маржа магазина.
  if (form.kind === 'internal' || form.kind === 'portion') {
    form.price_internal = null
    form.price_shop = null
  } else if (form.kind === 'tool' || form.kind === 'project' || form.kind === 'building') {
    form.price_internal = withVat
    form.price_shop = null
  } else if (form.kind === 'service') {
    form.price_internal = withProfit
    form.price_shop = null
  } else if (form.kind === 'raw' || form.kind === 'good' || form.kind === 'pack') {
    form.price_internal = withProfit
    form.price_shop = shop
  } else {
    form.price_internal = withProfit
    form.price_shop = shop
  }

  const parts = [
    `ингр. ${ceil2(ingCost) ?? 0}`,
    `труд ${ceil2(laborCost) ?? 0}`,
    workers > 1 ? `×${workers} чел.` : null,
    shiftShare !== 1 ? `смена ${Math.round(shiftShare * 100)}%` : null,
    amort ? `аморт ${ceil2(amort)}` : null,
    transport ? `тр. ${ceil2(transport)}` : null,
    tara ? `тара ${ceil2(tara)}` : null,
    prod90 ? `произв90 ${prod90.toFixed(1)}` : null,
  ]
  if (form.kind === 'tool' || form.kind === 'project' || form.kind === 'building') {
    parts.push(`×${(1 + prodR).toFixed(2)} маржа`)
    parts.push(`×${(1 + vatR).toFixed(2)} НДС`)
    parts.push('без маг.')
  } else if (form.kind === 'service') {
    parts.push(`×${(1 + prodR).toFixed(2)} маржа`)
    parts.push('без НДС / маг.')
  } else if (['raw', 'good', 'pack'].includes(form.kind)) {
    parts.push(`×${(1 + prodR).toFixed(2)} маржа`)
    parts.push(`×${(1 + vatR).toFixed(2)} НДС`)
    parts.push(`×${(1 + shopR).toFixed(2)} маг`)
  }
  let note = parts.filter(Boolean).join(' · ')
  if (missing.length) note += ` · нет цены: ${missing.join(', ')} (взято 0)`
  recalcNote.value = note
  info.value = 'Цены пересчитаны (не забудьте Сохранить)'
}

function applyAmortFromTool() {
  const tool = skuByKey(amortDraft.tool_sku_key)
  if (!tool) {
    error.value = 'Выберите инструмент'
    return
  }
  const price = ingredientUnitCost(tool)
  if (price == null) {
    error.value = `У «${tool.name_ru}» нет цены для амортизации`
    return
  }
  const life = Number(amortDraft.life_uses)
  if (!life || life <= 0) {
    error.value = 'Укажите ресурс инструмента (число использований)'
    return
  }
  form.cost_amort = ceil2(price / life)
  form.tool_name = tool.name_ru
  info.value = `Аморт: ${form.cost_amort} = ${price} / ${life} исп.`
}

function applyTaraFromSku(row) {
  if (row) taraDraft.pack_sku_key = row.sku_key
  const pack = row || skuByKey(taraDraft.pack_sku_key)
  if (!pack) {
    error.value = 'Выберите тару'
    return
  }
  const price = ingredientUnitCost(pack)
  if (price == null) {
    error.value = `У «${pack.name_ru}» нет цены`
    return
  }
  const qty = Number(form.pack_qty)
  if (!qty || qty <= 0) {
    error.value = 'Укажите «ед. в тару» (сколько единиц продукта в одной таре)'
    return
  }
  form.cost_tara = ceil2(price / qty)
  info.value = `Тара / ед.: ${form.cost_tara} = ${price} / ${qty}`
}

/** Пачка/бочка магазина → расходная единица (лист, яйцо, 1 л). */
function onUnpackPackPick(row) {
  if (!row) return
  unpackDraft.pack_sku_key = row.sku_key
  const n = Number(row.contents_qty)
  if (n > 0) unpackDraft.contents_qty = n
  else {
    const m = String(row.name_ru || '').match(/(\d+(?:[.,]\d+)?)\s*(?:лист|шт|л\b)/i)
    if (m) unpackDraft.contents_qty = Number(m[1].replace(',', '.'))
  }
}

function applyUnpackFromShop() {
  error.value = ''
  info.value = ''
  const pack = skuByKey(unpackDraft.pack_sku_key)
  if (!pack) {
    error.value = 'Выберите пачку / бочку магазина'
    return
  }
  const n = Number(unpackDraft.contents_qty) || Number(pack.contents_qty)
  if (!(n > 0)) {
    error.value = 'Укажите, сколько единиц в пачке (листы, шт, литры)'
    return
  }
  const shop = pack.price_shop != null && pack.price_shop !== '' ? ceil2(pack.price_shop) : null
  const costP = pack.cost != null && pack.cost !== '' ? ceil2(pack.cost) : null
  const useShop = unpackDraft.use_shop !== false
  const base = useShop ? shop ?? costP : costP ?? shop
  const src = useShop && shop != null ? 'маг.' : costP != null ? 'себест' : 'маг.'
  if (base == null) {
    error.value = `У «${pack.name_ru}» нет цены магазина / себестоимости`
    return
  }
  form.cost = ceil2(base / n)
  form.kind = 'portion'
  form.is_shop = 0
  form.price_shop = null
  form.ingredients = [
    {
      ingredient_name_ru: pack.name_ru,
      ingredient_sku_key: pack.sku_key,
      qty: qtyPrec(1 / n),
      unit_weight: null,
      sort_order: 0,
    },
  ]
  // Расщепление — не производство: не тянем ФОТ листа «с нуля»
  if (!(Number(form.labor_min) > 0) || Number(form.labor_min) >= Number(pack.labor_min || 0)) {
    form.labor_min = 0
    form.shift_pct = 0
  }
  info.value = `Расход из пачки: ${base} (${src}) ÷ ${n} = ${form.cost} за ед. Рецепт: 1/${n} пачки. Нажмите «Пересчитать» / «Сохранить».`
}

/** Разобрать «Ящик 0,5 на 0,5» → см (значения ≤3 считаем метрами). */
function parseNamePairCm(name) {
  const m = String(name || '').match(/(\d+(?:[.,]\d+)?)\s*(?:на|x|х|×)\s*(\d+(?:[.,]\d+)?)/i)
  if (!m) return null
  let a = Number(m[1].replace(',', '.'))
  let b = Number(m[2].replace(',', '.'))
  if (!Number.isFinite(a) || !Number.isFinite(b) || a <= 0 || b <= 0) return null
  if (a <= 3 && b <= 3) {
    a *= 100
    b *= 100
  }
  return { length_cm: a, width_cm: b, height_cm: null, source: 'name' }
}

/** Габариты SKU в см: поля формы/каталога, иначе пара из названия. */
function skuDimsCm(row) {
  if (!row) return null
  const shape = row.shape || 'none'
  let l = Number(row.length_cm)
  let w = Number(row.width_cm)
  let h = Number(row.height_cm)
  if (shape === 'cylinder') {
    const d = Number(row.width_cm) || Number(row.length_cm)
    if (d > 0) {
      l = d
      w = d
    }
  }
  const hasL = Number.isFinite(l) && l > 0
  const hasW = Number.isFinite(w) && w > 0
  const hasH = Number.isFinite(h) && h > 0
  if (hasL || hasW || hasH) {
    return {
      length_cm: hasL ? l : null,
      width_cm: hasW ? w : null,
      height_cm: hasH ? h : null,
      source: 'dims',
    }
  }
  return parseNamePairCm(row.name_ru)
}

function dimsLabel(d, { floorOnly = false } = {}) {
  if (!d) return '—'
  const parts = floorOnly
    ? [d.length_cm, d.width_cm].filter((x) => x != null)
    : [d.length_cm, d.width_cm, d.height_cm].filter((x) => x != null)
  return parts.map((x) => (Number.isInteger(x) ? String(x) : String(x))).join('×') + ' см'
}

/**
 * Сколько item влезает в тару по полу: ширина×длина (у цилиндра — диаметр×диаметр).
 * Высоту не учитываем — горшок 10 см ⌀ в ящик 50×50 → 25 шт.
 */
function fitCountInPack(item, pack) {
  if (!item || !pack) return null
  const iAxes = [item.length_cm, item.width_cm].filter((x) => x != null && x > 0)
  const pAxes = [pack.length_cm, pack.width_cm].filter((x) => x != null && x > 0)
  if (iAxes.length < 2 || pAxes.length < 2) return null

  const iList = iAxes.slice(0, 2)
  const pList = pAxes.slice(0, 2)

  function permutations(arr) {
    if (arr.length <= 1) return [arr]
    const out = []
    for (let i = 0; i < arr.length; i++) {
      const rest = [...arr.slice(0, i), ...arr.slice(i + 1)]
      for (const p of permutations(rest)) out.push([arr[i], ...p])
    }
    return out
  }

  let best = 0
  let bestLayout = null
  for (const ori of permutations(iList)) {
    let n = 1
    const cells = []
    let ok = true
    for (let a = 0; a < pList.length; a++) {
      const cell = Math.floor(pList[a] / ori[a] + 1e-9)
      if (cell < 1) {
        ok = false
        break
      }
      cells.push(cell)
      n *= cell
    }
    if (ok && n > best) {
      best = n
      bestLayout = cells
    }
  }
  if (best < 1) return null
  return { qty: best, layout: bestLayout, item, pack, mode: '2d' }
}

const packFitPreview = computed(() => {
  const pack = skuByKey(taraDraft.pack_sku_key)
  if (!pack) return null
  const item = {
    name_ru: form.name_ru,
    shape: form.shape,
    length_cm: form.length_cm,
    width_cm: form.width_cm,
    height_cm: form.height_cm,
  }
  const itemDims = skuDimsCm(item)
  const packDims = skuDimsCm(pack)
  if (!itemDims || !packDims) {
    return {
      ok: false,
      reason: !itemDims
        ? 'у товара нет размеров'
        : 'у тары нет размеров (задайте в карточке или в названии «0,5 на 0,5»)',
      itemDims,
      packDims,
    }
  }
  const fit = fitCountInPack(itemDims, packDims)
  if (!fit) {
    return {
      ok: false,
      reason: 'не влезает / мало осей (нужны хотя бы длина и ширина у обоих)',
      itemDims,
      packDims,
    }
  }
  return {
    ok: true,
    qty: fit.qty,
    layout: fit.layout,
    mode: fit.mode,
    itemDims,
    packDims,
  }
})

function applyPackQtyFromDims() {
  const prev = packFitPreview.value
  if (!prev?.ok) {
    error.value = prev?.reason || 'Нельзя посчитать ед. в тару по размерам'
    return
  }
  form.pack_qty = prev.qty
  const lay = prev.layout.join('×')
  info.value = `Ед. в тару: ${prev.qty} (${dimsLabel(prev.itemDims, { floorOnly: true })} в ${dimsLabel(prev.packDims, { floorOnly: true })}, расклад ${lay})`
}

function onPackPick(row) {
  if (!row) return
  taraDraft.pack_sku_key = row.sku_key
  const prev = packFitPreview.value
  if (prev?.ok && (form.pack_qty == null || form.pack_qty === '' || Number(form.pack_qty) <= 0)) {
    form.pack_qty = prev.qty
    info.value = `Ед. в тару из размеров: ${prev.qty}`
  }
}

function applyTransportFromSku(row) {
  if (row) taraDraft.transport_sku_key = row.sku_key
  applyTransportPerUnit()
}

/**
 * Транспорт / ед. = (цена рейса × транспортировок/день) / выработка за день.
 * Выработка день = (мин_день / труд_мин) × util.
 */
function applyTransportPerUnit() {
  const tr = skuByKey(taraDraft.transport_sku_key)
  if (!tr) {
    error.value = 'Выберите транспорт'
    return
  }
  const price = ingredientUnitCost(tr)
  if (price == null) {
    error.value = `У «${tr.name_ru}» нет цены`
    return
  }
  const trips = Number(form.transport_trips_day)
  if (!trips || trips <= 0) {
    error.value = 'Укажите число транспортировок в день'
    return
  }
  const dayProd = prodPerDay()
  if (!(dayProd > 0)) {
    error.value = 'Нужен труд, мин (для выработки за день)'
    return
  }
  form.cost_transport = ceil2((price * trips) / dayProd)
  form.transport_name = tr.name_ru
  info.value = `Транспорт / ед.: ${form.cost_transport} = (${price} × ${trips}) / ${ceil2(dayProd)} ед./день`
}

function onTransportPick(row) {
  if (!row) return
  taraDraft.transport_sku_key = row.sku_key
  if (form.transport_trips_day > 0) applyTransportPerUnit()
}

function onIngredientPick(ing, row) {
  if (row) {
    ing.ingredient_sku_key = row.sku_key
    ing.ingredient_name_ru = row.name_ru
  } else {
    const found = skuByKey(ing.ingredient_sku_key)
    ing.ingredient_name_ru = found?.name_ru || ''
  }
}

function onNameInput() {
  if (!skuKeyLocked.value) {
    form.sku_key = slugFromName(form.name_ru)
  }
}

function onAddUnit(unit) {
  if (!extraUnits.value.includes(unit)) extraUnits.value.push(unit)
}

function shapeLabel(shape) {
  return SHAPES.find((s) => s.key === shape)?.ru || shape
}

function shapeSummary() {
  if (form.shape === 'none' || !form.shape) return ''
  if (form.shape === 'cylinder') {
    const d = form.width_cm
    const h = form.height_cm
    if (d == null && h == null) return 'размеры не заданы'
    const rho = form.density != null && form.density !== '' ? ` · ρ ${form.density}` : ''
    return `⌀ ${d ?? '—'} × выс. ${h ?? '—'} см${rho}`
  }
  const { length_cm: l, width_cm: w, height_cm: h } = form
  if (l == null && w == null && h == null) return 'размеры не заданы'
  const rho = form.density != null && form.density !== '' ? ` · ρ ${form.density}` : ''
  return `${l ?? '—'} × ${w ?? '—'} × ${h ?? '—'} см${rho}`
}

function openShapeModal() {
  if (form.shape === 'none') {
    shapeOpen.value = false
    return
  }
  shapeDraft.length_cm = form.length_cm
  shapeDraft.width_cm = form.width_cm
  shapeDraft.height_cm = form.height_cm
  shapeDraft.diameter_cm = form.shape === 'cylinder' ? form.width_cm : null
  shapeDraft.density = form.density
  const sug = suggestDensityLocal(form.name_ru, form.product_kind)
  densityPick.value = sug?.key || ''
  if ((shapeDraft.density == null || shapeDraft.density === '') && sug?.density != null) {
    shapeDraft.density = ceil2(sug.density)
  }
  shapeOpen.value = true
}

function onShapeChange() {
  if (form.shape === 'none') {
    form.width_cm = null
    form.length_cm = null
    form.height_cm = null
    shapeOpen.value = false
    return
  }
  openShapeModal()
}

function applyShapeDims() {
  if (form.shape === 'cylinder') {
    form.width_cm = shapeDraft.diameter_cm
    form.length_cm = shapeDraft.diameter_cm
    form.height_cm = shapeDraft.height_cm
  } else {
    form.length_cm = shapeDraft.length_cm
    form.width_cm = shapeDraft.width_cm
    form.height_cm = shapeDraft.height_cm
  }
  form.density = shapeDraft.density
  const w = weightFromShape(form.shape, shapeDraft)
  if (w != null) form.weight_kg = w
  shapeOpen.value = false
}

function cancelShapeDims() {
  shapeOpen.value = false
}

function slugFromName(name) {
  const m = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y',
    к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f',
    х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
  }
  let out = ''
  for (const ch of String(name || '').toLowerCase()) {
    if (m[ch] !== undefined) out += m[ch]
    else if (/[a-z0-9]/.test(ch)) out += ch
    else if (' ./-+'.includes(ch)) out += '_'
  }
  return out.replace(/_+/g, '_').replace(/^_|_$/g, '').slice(0, 64)
}

function kindRu(key) {
  return KINDS.find((k) => k.key === key)?.ru || key
}

function kindIcon(key) {
  return KINDS.find((k) => k.key === key)?.icon || 'stock'
}

function rowVisual(row) {
  return skuVisual(row)
}

function formatPrice(v) {
  const n = ceil2(v)
  return n == null ? null : n.toFixed(2)
}

function priceLabel(row) {
  return formatPrice(rowPriceNum(row)) ?? '—'
}

function resetForm() {
  editId.value = null
  formOpen.value = false
  recalcNote.value = ''
  skuKeyLocked.value = false
  amortDraft.tool_sku_key = ''
  amortDraft.life_uses = 1000
  taraDraft.pack_sku_key = ''
  taraDraft.transport_sku_key = ''
  Object.assign(form, emptyForm())
}

function startNew() {
  editId.value = null
  Object.assign(form, emptyForm())
  skuKeyLocked.value = false
  formOpen.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

/** Копия текущего SKU → режим «Создать» с теми же полями. */
function copySku() {
  if (!form.sku_key && !form.name_ru) return
  editId.value = null
  skuKeyLocked.value = false
  form.name_ru = `${form.name_ru || 'товар'} (копия)`
  form.sku_key = slugFromName(form.name_ru)
  if (form.icon_key) {
    /* оставляем ту же иконку — можно сменить вручную */
  }
  formOpen.value = true
  info.value = 'Копия: поменяйте название и сохраните («Создать»)'
  error.value = ''
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function closeForm() {
  resetForm()
}

function fillForm(row) {
  editId.value = row.id
  skuKeyLocked.value = true
  const blank = emptyForm()
  for (const k of Object.keys(blank)) {
    if (k === 'ingredients') continue
    const v = row[k] ?? blank[k]
    form[k] = NUM_FIELDS.includes(k) ? ceil2(v) : v
  }
  if (form.cost == null && row.cost_seb != null) form.cost = ceil2(row.cost_seb)
  if (!(Number(form.labor_workers) >= 1)) form.labor_workers = 1
  if (form.build_partitions == null) form.build_partitions = 1
  if (form.build_windows == null) form.build_windows = 2
  if (form.build_doors == null) form.build_doors = 2
  form.ingredients =
    Array.isArray(row.ingredients) && row.ingredients.length
      ? row.ingredients.map((ing, i) => ({
          ingredient_name_ru: ing.ingredient_name_ru || '',
          ingredient_sku_key: ing.ingredient_sku_key || '',
          qty: qtyPrec(ing.qty),
          unit_weight: ceil2(ing.unit_weight),
          sort_order: ing.sort_order ?? i,
        }))
      : [emptyIng()]
}

async function loadUser() {
  const me = await api.me()
  user.value = me.user
  if (!user.value || user.value.role !== 'admin') {
    throw new Error('Нужен admin')
  }
}

async function loadSkus() {
  const res = await api.catalogList(undefined, undefined)
  items.value = res.items || []
}

async function loadFiscal() {
  const res = await api.catalogFiscal()
  if (res.resolved) {
    Object.assign(fiscalForm, {
      scope_type: res.resolved.scope_type || 'global',
      world_id: res.resolved.world_id,
      title: res.resolved.title || 'Канон старта',
      vat_rate: Number(res.resolved.vat_rate),
      shop_margin_rate: Number(res.resolved.shop_margin_rate),
      producer_margin_rate: Number(res.resolved.producer_margin_rate),
      util_rate: Number(res.resolved.util_rate),
      labor_min_hour: Number(res.resolved.labor_min_hour),
      labor_min_day: Number(res.resolved.labor_min_day),
      labor_min_month: Number(res.resolved.labor_min_month),
    })
  }
}

async function loadDensities() {
  const res = await api.catalogDensities()
  densityItems.value = res.items || []
  densityForms.value = densityItems.value.map(densityToForm)
  if (!densityForms.value.length) densityForms.value = [emptyDensity()]
}

function addDensityRow() {
  densityForms.value.push(emptyDensity())
}

function removeDensityRow(i) {
  densityForms.value.splice(i, 1)
  if (!densityForms.value.length) densityForms.value = [emptyDensity()]
}

async function saveDensities() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const payload = densityForms.value.map(densityFromForm).filter((r) => r.key && r.name_ru)
    const res = await api.catalogDensitiesSave(payload)
    densityItems.value = res.items || []
    densityForms.value = densityItems.value.map(densityToForm)
    info.value = `Справочник плотности: ${densityItems.value.length} поз.`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

/** Шаблоны фирм (справочник). */
const firmPane = ref('templates') // templates | maklend
const firms = ref([])
const firmSel = ref('')
const firmForm = reactive({
  code: '',
  title: '',
  kind: 'firm',
  workers_default: 0,
  notes: '',
  products: [],
})

/** Конкретные фирмы поселения Макленд. */
const maklendMeta = ref(null)
const maklendNote = ref('')
const maklendFirms = ref([])
const maklendSel = ref(0)
/** Период отображения плана: day | week | month. В БД всегда шт/день. */
const maklendPeriod = ref('day')
const WORK_DAYS_WEEK = 3
const maklendForm = reactive({
  id: 0,
  code: '',
  title: '',
  kind: 'firm',
  workers: 0,
  capacity_min_day: null,
  used_min_day: null,
  load_pct: null,
  over_capacity: false,
  work_days_month: 9,
  products: [],
})

function emptyFirmProduct() {
  return { sku_key: '', name_ru: '', qty_day: null, qty_fact: null, labor_min: null }
}

function fillFirmForm(row) {
  firmForm.code = row?.code || ''
  firmForm.title = row?.title || ''
  firmForm.kind = row?.kind || 'firm'
  firmForm.workers_default = Number(row?.workers_default ?? 0)
  firmForm.notes = row?.notes || ''
  firmForm.products = (row?.products || []).map((p) => ({
    sku_key: p.sku_key || '',
    name_ru: p.name_ru || '',
    qty_day: p.qty_day == null || p.qty_day === '' ? null : Number(p.qty_day),
  }))
  if (!firmForm.products.length) firmForm.products = [emptyFirmProduct()]
}

const firmProductOptions = computed(() =>
  items.value.map((r) => ({
    id: r.id,
    sku_key: r.sku_key,
    name_ru: r.name_ru,
    unit: r.unit,
    kind: r.kind,
    icon_key: r.icon_key,
    icon_mark: r.icon_mark,
    labor_min: r.labor_min,
  })),
)

const firmQtyDayTotal = computed(() => {
  let sum = 0
  let any = false
  for (const p of firmForm.products) {
    const q = Number(p.qty_day)
    if (!(q > 0)) continue
    sum += q
    any = true
  }
  return any ? ceil2(sum) : null
})

/** Норма шт/день из labor_min: (мин/день ÷ труд) × 0.9 */
function qtyDayFromLabor(laborMin) {
  const labor = Number(laborMin)
  const dayMin = Number(fiscalForm.labor_min_day)
  if (!(labor > 0) || !(dayMin > 0)) return null
  return ceil2((dayMin / labor) * 0.9)
}

function selectFirm(code) {
  firmSel.value = code
  const row = firms.value.find((f) => f.code === code)
  if (row) fillFirmForm(row)
}

function addFirmProduct() {
  firmForm.products.push(emptyFirmProduct())
}

function removeFirmProduct(i) {
  firmForm.products.splice(i, 1)
  if (!firmForm.products.length) firmForm.products = [emptyFirmProduct()]
}

function onFirmProductPick(row, prod) {
  if (!row || !prod) return
  prod.sku_key = row.sku_key || prod.sku_key
  prod.name_ru = row.name_ru || prod.name_ru || ''
  prod.labor_min = row.labor_min ?? prod.labor_min ?? null
  const suggested = qtyDayFromLabor(row.labor_min)
  if (suggested != null) prod.qty_day = suggested
}

async function openFirmProduct(skuKey) {
  if (!skuKey) return
  const row = items.value.find((r) => r.sku_key === skuKey)
  if (!row?.id) {
    error.value = `SKU не найден: ${skuKey}`
    return
  }
  tab.value = 'skus'
  await openEdit(row.id)
}

function fillMaklendForm(row) {
  maklendForm.id = row?.id || 0
  maklendForm.code = row?.code || ''
  maklendForm.title = row?.title || ''
  maklendForm.kind = row?.kind || 'firm'
  maklendForm.workers = Number(row?.workers ?? 0)
  maklendForm.capacity_min_day = row?.capacity_min_day ?? null
  maklendForm.used_min_day = row?.used_min_day ?? null
  maklendForm.load_pct = row?.load_pct ?? null
  maklendForm.over_capacity = !!row?.over_capacity
  maklendForm.work_days_month = Number(row?.work_days_month ?? 9)
  maklendForm.products = (row?.products || []).map((p) => ({
    sku_key: p.sku_key || '',
    name_ru: p.name_ru || '',
    labor_min: p.labor_min == null ? null : Number(p.labor_min),
    qty_day: p.qty_day == null || p.qty_day === '' ? null : Number(p.qty_day),
    qty_fact: p.qty_fact == null || p.qty_fact === '' ? null : Number(p.qty_fact),
  }))
  if (!maklendForm.products.length) maklendForm.products = [emptyFirmProduct()]
}

function selectMaklend(id) {
  maklendSel.value = id
  const row = maklendFirms.value.find((f) => f.id === id)
  if (row) fillMaklendForm(row)
}

const maklendWorkDaysMonth = computed(() => {
  const day = Number(fiscalForm.labor_min_day)
  const month = Number(fiscalForm.labor_min_month)
  if (day > 0 && month > 0) return month / day
  return Number(maklendForm.work_days_month) || 9
})

const maklendPeriodFactor = computed(() => {
  if (maklendPeriod.value === 'week') return WORK_DAYS_WEEK
  if (maklendPeriod.value === 'month') return maklendWorkDaysMonth.value
  return 1
})

const maklendPeriodLabel = computed(() => {
  if (maklendPeriod.value === 'week') return 'нед'
  if (maklendPeriod.value === 'month') return 'мес'
  return 'день'
})

const maklendAltLabel = computed(() => {
  if (maklendPeriod.value === 'month') return '≈день'
  return '≈мес'
})

function qtyToPeriod(dayQty) {
  const q = Number(dayQty)
  if (!(q > 0)) return null
  return ceil2(q * maklendPeriodFactor.value)
}

function periodToDayQty(periodQty) {
  if (periodQty === '' || periodQty == null) return null
  const q = Number(periodQty)
  if (!Number.isFinite(q)) return null
  const f = maklendPeriodFactor.value
  if (!(f > 0)) return null
  return ceil2(q / f)
}

function setMaklendPlan(prod, periodQty) {
  prod.qty_day = periodToDayQty(periodQty)
}

function setMaklendFact(prod, periodQty) {
  prod.qty_fact = periodToDayQty(periodQty)
}

function maklendAltQty(dayQty) {
  const q = Number(dayQty)
  if (!(q > 0)) return null
  if (maklendPeriod.value === 'month') return ceil2(q)
  return ceil2(q * maklendWorkDaysMonth.value)
}

const maklendQtyDayTotal = computed(() => {
  let sum = 0
  let any = false
  for (const p of maklendForm.products) {
    const q = Number(p.qty_day)
    if (!(q > 0)) continue
    sum += q
    any = true
  }
  return any ? ceil2(sum) : null
})

const maklendQtyPeriodTotal = computed(() => qtyToPeriod(maklendQtyDayTotal.value))

const maklendUsedMinLive = computed(() => {
  let sum = 0
  let any = false
  for (const p of maklendForm.products) {
    const q = Number(p.qty_day)
    const labor = Number(p.labor_min)
    if (!(q > 0) || !(labor > 0)) {
      const opt = firmProductOptions.value.find((o) => o.sku_key === p.sku_key)
      const l2 = Number(opt?.labor_min)
      if (!(q > 0) || !(l2 > 0)) continue
      sum += q * l2
      any = true
      continue
    }
    sum += q * labor
    any = true
  }
  if (!any) return null
  return ceil2(sum * maklendPeriodFactor.value)
})

const maklendCapLive = computed(() => {
  const w = Number(maklendForm.workers)
  const day = Number(fiscalForm.labor_min_day)
  if (!(w > 0) || !(day > 0)) return null
  return ceil2(w * day * maklendPeriodFactor.value)
})

const maklendLoadLive = computed(() => {
  const used = maklendUsedMinLive.value
  const cap = maklendCapLive.value
  if (used == null || !(cap > 0)) return null
  return ceil2((used / cap) * 100)
})

const maklendCapPeriodHint = computed(() => {
  if (maklendPeriod.value === 'week') return `${WORK_DAYS_WEEK} раб.дня`
  if (maklendPeriod.value === 'month') return `${maklendWorkDaysMonth.value} раб.дней`
  return '1 смена'
})

function addMaklendProduct() {
  maklendForm.products.push(emptyFirmProduct())
}

function removeMaklendProduct(i) {
  maklendForm.products.splice(i, 1)
  if (!maklendForm.products.length) maklendForm.products = [emptyFirmProduct()]
}

function onMaklendProductPick(row, prod) {
  onFirmProductPick(row, prod)
}

async function loadMaklendFirms() {
  const res = await api.catalogMaklendFirms()
  maklendMeta.value = res.settlement || null
  maklendNote.value = res.note || ''
  maklendFirms.value = res.items || []
  if (maklendSel.value) {
    const still = maklendFirms.value.find((f) => f.id === maklendSel.value)
    if (still) fillMaklendForm(still)
    else if (maklendFirms.value.length) selectMaklend(maklendFirms.value[0].id)
    else {
      maklendSel.value = 0
      fillMaklendForm(null)
    }
  } else if (maklendFirms.value.length) {
    selectMaklend(maklendFirms.value[0].id)
  }
}

async function saveMaklendFirm() {
  if (!maklendForm.id) return
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const products = maklendForm.products
      .filter((p) => p.sku_key)
      .map((p, i) => ({
        sku_key: p.sku_key,
        qty_day: p.qty_day === '' || p.qty_day == null ? null : Number(p.qty_day),
        qty_fact: p.qty_fact === '' || p.qty_fact == null ? null : Number(p.qty_fact),
        sort_order: i,
      }))
    const res = await api.catalogMaklendFirmSave(maklendForm.id, {
      title: maklendForm.title,
      kind: maklendForm.kind,
      workers: Number(maklendForm.workers) || 0,
      products,
    })
    const idx = maklendFirms.value.findIndex((f) => f.id === maklendForm.id)
    if (idx >= 0) maklendFirms.value[idx] = res.item
    else maklendFirms.value.push(res.item)
    fillMaklendForm(res.item)
    info.value = `Макленд · ${maklendForm.code}: сохранено`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function loadFirms() {
  const res = await api.catalogFirms()
  firms.value = res.items || []
  if (firmSel.value) {
    const still = firms.value.find((f) => f.code === firmSel.value)
    if (still) fillFirmForm(still)
    else {
      firmSel.value = ''
      fillFirmForm(null)
    }
  } else if (firms.value.length) {
    selectFirm(firms.value[0].code)
  }
}

async function saveFirm() {
  if (!firmForm.code) return
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const products = firmForm.products
      .filter((p) => p.sku_key)
      .map((p, i) => ({
        sku_key: p.sku_key,
        qty_day: p.qty_day === '' || p.qty_day == null ? null : Number(p.qty_day),
        sort_order: i,
      }))
    const res = await api.catalogFirmSave(firmForm.code, {
      title: firmForm.title,
      kind: firmForm.kind,
      workers_default: Number(firmForm.workers_default) || 0,
      notes: firmForm.notes || null,
      products,
    })
    const idx = firms.value.findIndex((f) => f.code === firmForm.code)
    if (idx >= 0) firms.value[idx] = res.item
    else firms.value.push(res.item)
    fillFirmForm(res.item)
    info.value = `Фирма ${firmForm.code}: сохранено`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function attachFirmsFromSeed() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const res = await api.catalogFirmsAttachSeed()
    firms.value = res.items || []
    const still = firms.value.find((f) => f.code === firmSel.value)
    if (still) fillFirmForm(still)
    else if (firms.value.length) selectFirm(firms.value[0].code)
    const unknown = (res.unknown || []).length ? ` · без фирмы: ${res.unknown.join(', ')}` : ''
    info.value = `Из сида привязано: ${res.added ?? 0}${unknown}`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function reseedFirms() {
  if (!confirm('Перезалить шаблоны фирм из каталога? Ручные нормы шт/день будут сброшены.')) return
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const res = await api.catalogFirmsSeed(true)
    await loadFirms()
    info.value = `Фирмы: ${res.firms ?? 0}, продуктов: ${res.products ?? 0}`
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function openEdit(id) {
  busy.value = true
  error.value = ''
  try {
    const res = await api.catalogGet(id)
    fillForm(res.item)
    formOpen.value = true
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function addIngredient() {
  form.ingredients.push(emptyIng())
}

function removeIngredient(i) {
  form.ingredients.splice(i, 1)
  if (!form.ingredients.length) form.ingredients.push(emptyIng())
}

async function saveSku() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    if (!form.sku_key && form.name_ru) form.sku_key = slugFromName(form.name_ru)
    if (!form.icon_key && form.sku_key) form.icon_key = skuIcon(form.sku_key)
    const payload = {
      ...form,
      ingredients: form.ingredients.filter((x) => x.ingredient_sku_key && x.ingredient_name_ru),
    }
    if (editId.value) {
      await api.catalogUpdate(editId.value, payload)
      info.value = 'Сохранено'
      formOpen.value = true
    } else {
      await api.catalogCreate(payload)
      info.value = 'Создано'
      closeForm()
    }
    await loadSkus()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function archiveSku() {
  if (!editId.value) return
  if (!confirm('Отправить в архив? Позиция исчезнет из списка.')) return
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    await api.catalogUpdate(editId.value, { is_active: 0 })
    info.value = 'В архиве'
    closeForm()
    await loadSkus()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function saveFiscal() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const payload = { ...fiscalForm }
    if (payload.scope_type === 'global') payload.world_id = null
    await api.catalogFiscalSave(payload)
    info.value = 'Константы сохранены'
    await loadFiscal()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function reseed() {
  if (!confirm('Перезалить каталог из seed? Правки в ref_sku пропадут.')) return
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const res = await api.catalogSeed(true)
    info.value = res.skipped ? 'Пропущено' : `Импорт: ${res.imported}`
    await loadSkus()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await loadUser()
    await loadSkus()
    await loadFiscal()
    await loadDensities()
    await loadFirms()
    await loadMaklendFirms()
  } catch (e) {
    error.value = e.message
  }
})
</script>

<template>
  <section class="panel">
    <div class="head">
      <div>
        <router-link class="back" to="/">← на главную</router-link>
        <h1>Каталог</h1>
        <p class="lead">
          Товары (Sheet), постройки, фирмы, НДС — и справочники фазы 0 (семейства, потребности, знания,
          калории). Аккаунт — в
          <router-link to="/settings">Settings</router-link>.
        </p>
      </div>
      <p v-if="user" class="who">{{ user.login }} · {{ user.role }}</p>
    </div>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="info" class="ok">{{ info }}</p>

    <div class="tabs">
      <button type="button" :class="{ on: tab === 'skus' }" @click="tab = 'skus'">Товары</button>
      <button type="button" :class="{ on: tab === 'builds' }" @click="openBuildsTab">Постройки</button>
      <button type="button" :class="{ on: tab === 'fiscal' }" @click="tab = 'fiscal'">НДС / маржи</button>
      <button type="button" :class="{ on: tab === 'density' }" @click="tab = 'density'">Плотности</button>
      <button type="button" :class="{ on: tab === 'firms' }" @click="tab = 'firms'">Фирмы</button>
      <button type="button" :class="{ on: tab === 'families' }" @click="tab = 'families'">Семейства</button>
      <button type="button" :class="{ on: tab === 'click-needs' }" @click="tab = 'click-needs'">
        Потребности
      </button>
      <button type="button" :class="{ on: tab === 'knowledge' }" @click="tab = 'knowledge'">Знания</button>
      <button type="button" :class="{ on: tab === 'calories' }" @click="tab = 'calories'">Калории</button>
    </div>

    <div v-if="['families', 'click-needs', 'knowledge', 'calories'].includes(tab)">
      <RefCrudPanel
        :resource="tab"
        @error="(m) => (error = m)"
        @info="(m) => (info = m)"
      />
    </div>

    <div v-else-if="tab === 'builds'" class="box">
      <h2>Постройки</h2>
      <p class="hint">
        Создание пары SKU: <b>проект</b> (ПрБюро) + <b>здание</b> (СтройКом). Схема сруба → сырьё → цена с маржой и НДС.
        Рост куба {{ CUBE_HEIGHT_CM }} см, потолок ≈ 105 см (7 рядов).
      </p>
      <div class="toolbar">
        <button type="button" class="primary" :disabled="busy" @click="startNewBuild">+ Новое здание</button>
      </div>

      <div v-if="buildFormOpen" class="box build-box">
        <h3>{{ buildEditKey ? `Правка «${buildForm.name_ru}»` : 'Новое здание' }}</h3>
        <div class="grid3">
          <label class="span2">
            Название
            <input v-model="buildForm.name_ru" placeholder="Ратуша" />
          </label>
          <label>
            Ключ здания
            <input v-model="buildForm.sku_key" :placeholder="slugFromName(buildForm.name_ru) || 'ratusha'" />
          </label>
          <label>
            Ширина, см
            <input v-model.number="buildForm.width_cm" type="number" step="1" min="0" />
          </label>
          <label>
            Длина, см
            <input v-model.number="buildForm.length_cm" type="number" step="1" min="0" />
          </label>
          <label>
            Высота стен, см
            <input v-model.number="buildForm.height_cm" type="number" step="1" min="0" />
          </label>
          <label>
            Перегородки
            <input v-model.number="buildForm.build_partitions" type="number" step="1" min="0" />
          </label>
          <label>
            Окна
            <input v-model.number="buildForm.build_windows" type="number" step="1" min="0" />
          </label>
          <label>
            Двери
            <input v-model.number="buildForm.build_doors" type="number" step="1" min="0" />
          </label>
          <label>
            Строит
            <FirmPick v-model="buildForm.builder_code" :options="producers" />
          </label>
          <label>
            Проект (бюро)
            <FirmPick v-model="buildForm.bureau_code" :options="producers" />
          </label>
        </div>
        <pre v-if="buildPreview?.ok" class="build-scheme">{{ buildingSchemeText(buildPreview) }}</pre>
        <p v-else-if="buildPreview && !buildPreview.ok" class="hint warn">{{ buildPreview.error }}</p>
        <div class="actions">
          <button type="button" class="primary" :disabled="busy" @click="saveBuildingPair">
            Сохранить проект + здание
          </button>
          <button type="button" class="ghost" @click="closeBuildForm">Отмена</button>
        </div>
      </div>

      <div v-if="!buildRows.length" class="hint">Пока нет зданий — создайте первое.</div>
      <table v-else class="table builds-table">
        <thead>
          <tr>
            <th>Здание</th>
            <th>Габарит</th>
            <th>Сырьё (бр/кам/дос)</th>
            <th>Цена здания</th>
            <th>Проект</th>
            <th>Цена проекта</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in buildRows" :key="row.building.id">
            <td>
              <strong>{{ row.building.name_ru }}</strong>
              <div class="muted">{{ row.building.sku_key }} · {{ row.building.producer_code }}</div>
            </td>
            <td>
              {{ row.building.width_cm }}×{{ row.building.length_cm }}×{{ row.building.height_cm }}
              <div class="muted">{{ row.scheme?.courses || '—' }} рядов</div>
            </td>
            <td class="muted">
              {{ row.scheme?.qty?.brevno ?? '—' }} /
              {{ row.scheme?.qty?.kamen_dlya_pola ?? '—' }} /
              {{ row.scheme?.qty?.doska ?? '—' }}
            </td>
            <td>{{ formatPrice(row.building.price_internal ?? row.building.cost) ?? '—' }}</td>
            <td>
              <template v-if="row.project">
                {{ row.project.name_ru }}
                <div class="muted">{{ row.project.sku_key }}</div>
              </template>
              <span v-else class="muted">нет пары</span>
            </td>
            <td>{{ row.project ? formatPrice(row.project.price_internal ?? row.project.cost) ?? '—' : '—' }}</td>
            <td class="actions-cell">
              <button type="button" class="ghost" @click="editBuildRow(row)">Изменить</button>
              <button type="button" class="ghost" @click="openFirmProduct(row.building.sku_key)">SKU</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else-if="tab === 'fiscal'" class="box">
      <h2>Фискальные константы</h2>
      <p class="hint">НДС 7%, маржа магазина 10%, маржа производителя 20% — на государство (global / world).</p>
      <div class="grid3">
        <label>
          Область
          <select v-model="fiscalForm.scope_type">
            <option value="global">global</option>
            <option value="world">world</option>
          </select>
        </label>
        <label v-if="fiscalForm.scope_type === 'world'">
          world_id
          <input v-model.number="fiscalForm.world_id" type="number" min="1" />
        </label>
        <label>Название <input v-model="fiscalForm.title" /></label>
        <label>НДС <input v-model.number="fiscalForm.vat_rate" type="number" step="0.01" /></label>
        <label>Маржа магазина <input v-model.number="fiscalForm.shop_margin_rate" type="number" step="0.01" /></label>
        <label>
          Маржа производителя
          <input v-model.number="fiscalForm.producer_margin_rate" type="number" step="0.01" />
        </label>
      </div>
      <div class="actions">
        <button type="button" class="primary" :disabled="busy" @click="saveFiscal">Сохранить</button>
      </div>
    </div>

    <div v-else-if="tab === 'density'" class="box">
      <h2>Справочник плотности</h2>
      <p class="hint">
        г/см³ (= кг/л). В форме размеров можно выбрать материал — подставится ρ и вес.
        Сопоставление: сначала «вид продукции», затем подстрока в названии.
      </p>
      <div v-for="(row, i) in densityForms" :key="i" class="dens-row">
        <label>
          Ключ
          <input v-model="row.key" placeholder="clay_wet" />
        </label>
        <label>
          Название
          <input v-model="row.name_ru" placeholder="Глина сырая" />
        </label>
        <label>
          ρ, г/см³
          <input v-model.number="row.density" type="number" step="0.01" min="0" />
        </label>
        <label class="span2">
          Виды (product_kind)
          <input v-model="row.product_kinds" placeholder="Глина, Вода — через запятую" />
        </label>
        <label class="span2">
          Подстроки названия
          <input v-model="row.name_match" placeholder="глина, вода" />
        </label>
        <label class="span2">
          Заметка
          <input v-model="row.notes" />
        </label>
        <button type="button" class="ghost dens-del" @click="removeDensityRow(i)">×</button>
      </div>
      <div class="actions">
        <button type="button" class="ghost" @click="addDensityRow">+ материал</button>
        <button type="button" class="primary" :disabled="busy" @click="saveDensities">Сохранить</button>
      </div>
    </div>

    <div v-else-if="tab === 'firms'" class="box">
      <div class="firm-subtabs">
        <button
          type="button"
          :class="{ on: firmPane === 'templates' }"
          @click="firmPane = 'templates'"
        >
          Шаблоны
        </button>
        <button
          type="button"
          :class="{ on: firmPane === 'maklend' }"
          @click="firmPane = 'maklend'"
        >
          Макленд
        </button>
      </div>

      <template v-if="firmPane === 'templates'">
      <div class="firm-head">
        <h2>Шаблоны фирм</h2>
        <button type="button" class="ghost" :disabled="busy" @click="attachFirmsFromSeed">
          Продукция из сида
        </button>
        <button type="button" class="ghost danger" :disabled="busy" @click="reseedFirms">
          Перезалить из каталога
        </button>
      </div>
      <p class="hint">
        Справочник для основания компаний в поселениях. Сотрудники — штат по умолчанию; напротив продукта —
        норма шт/день (пока правится вручную).
      </p>
      <div class="firm-layout">
        <ul class="firm-list">
          <li v-for="f in firms" :key="f.code">
            <button
              type="button"
              class="firm-list-btn"
              :class="{ on: firmSel === f.code }"
              @click="selectFirm(f.code)"
            >
              <img
                v-if="firmLogoSrc(f.code)"
                class="firm-logo"
                :src="firmLogoSrc(f.code)"
                alt=""
              />
              <span class="firm-list-meta">
                <strong>{{ f.code }}</strong>
                <small>{{ f.workers_default }} чел. · {{ (f.products || []).length }} поз.</small>
              </span>
            </button>
          </li>
        </ul>
        <div v-if="firmSel" class="firm-card">
          <div class="grid3">
            <label>
              Код
              <input :value="firmForm.code" readonly class="readonly-field" />
            </label>
            <label>
              Название
              <input v-model="firmForm.title" />
            </label>
            <label>
              Сотрудников
              <input v-model.number="firmForm.workers_default" type="number" min="0" step="1" />
            </label>
            <label>
              Вид
              <select v-model="firmForm.kind">
                <option value="firm">firm</option>
                <option value="shop">shop</option>
                <option value="bank">bank</option>
                <option value="treasury">treasury</option>
              </select>
            </label>
            <label class="span2">
              Заметки
              <input v-model="firmForm.notes" />
            </label>
          </div>

          <h3>Продукция</h3>
          <div v-for="(prod, i) in firmForm.products" :key="i" class="firm-prod-row">
            <button
              type="button"
              class="firm-prod-info"
              :disabled="!prod.sku_key"
              title="Открыть карточку товара"
              @click="openFirmProduct(prod.sku_key)"
            >
              i
            </button>
            <SkuPick
              class="firm-sku-pick"
              v-model="prod.sku_key"
              :options="firmProductOptions"
              placeholder="продукт…"
              @pick="(row) => onFirmProductPick(row, prod)"
            />
            <input
              v-model.number="prod.qty_day"
              type="number"
              step="0.01"
              min="0"
              placeholder="план"
              title="Теоретическая норма шт/день"
            />
            <button type="button" class="ghost" @click="removeFirmProduct(i)">×</button>
          </div>
          <p v-if="firmQtyDayTotal != null" class="ing-total">
            Итого норм: <b>{{ firmQtyDayTotal }}</b> шт/день
          </p>
          <div class="actions">
            <button type="button" class="ghost" @click="addFirmProduct">+ продукт</button>
            <button type="button" class="primary" :disabled="busy" @click="saveFirm">Сохранить</button>
          </div>
        </div>
        <p v-else class="hint">Выберите фирму слева</p>
      </div>
      </template>

      <template v-else>
      <div class="firm-head">
        <h2>Фирмы Макленд</h2>
        <button type="button" class="ghost" :disabled="busy" @click="loadMaklendFirms">Обновить</button>
      </div>
      <p class="hint">
        План выпуска поселения: правим <b>шт/день</b> (как в тике). Месяц = шт/день ×
        {{ fiscalForm.labor_min_month / fiscalForm.labor_min_day || 9 }} раб.дней
        ({{ fiscalForm.labor_min_month }} мин / {{ fiscalForm.labor_min_day }} мин смены).
        Загрузка смены = Σ(шт×труд) / (сотрудники × мин/день).
      </p>
      <p v-if="maklendMeta" class="hint">
        Мир «{{ maklendMeta.world_name }}» · день {{ maklendMeta.day }}.{{ maklendMeta.month }}.{{
          maklendMeta.year
        }}
        · население {{ maklendMeta.population }}
      </p>
      <p v-else-if="maklendNote" class="hint warn-text">{{ maklendNote }}</p>
      <div v-if="maklendFirms.length" class="firm-layout">
        <ul class="firm-list">
          <li v-for="f in maklendFirms" :key="f.id">
            <button
              type="button"
              class="firm-list-btn"
              :class="{ on: maklendSel === f.id, over: f.over_capacity }"
              @click="selectMaklend(f.id)"
            >
              <img
                v-if="firmLogoSrc(f.code)"
                class="firm-logo"
                :src="firmLogoSrc(f.code)"
                alt=""
              />
              <span class="firm-list-meta">
                <strong>{{ f.code }}</strong>
                <small
                  >{{ f.workers }} чел. ·
                  {{ f.load_pct != null ? f.load_pct + '%' : '—' }}</small
                >
              </span>
            </button>
          </li>
        </ul>
        <div v-if="maklendSel" class="firm-card">
          <div class="grid3">
            <label>
              Код
              <input :value="maklendForm.code" readonly class="readonly-field" />
            </label>
            <label>
              Название
              <input v-model="maklendForm.title" />
            </label>
            <label>
              Сотрудников
              <input v-model.number="maklendForm.workers" type="number" min="0" step="1" />
            </label>
          </div>
          <p
            class="cap-line"
            :class="{ over: maklendLoadLive != null && maklendLoadLive > 100 }"
          >
            Фонд ({{ maklendCapPeriodHint }}):
            <b>{{ maklendUsedMinLive ?? '—' }}</b> /
            {{ maklendCapLive ?? '—' }} мин
            <template v-if="maklendLoadLive != null">
              · <b>{{ maklendLoadLive }}%</b>
            </template>
          </p>

          <div class="plan-period-row">
            <h3>План выпуска</h3>
            <div class="firm-subtabs plan-period-tabs">
              <button
                type="button"
                :class="{ on: maklendPeriod === 'day' }"
                @click="maklendPeriod = 'day'"
              >
                1 день
              </button>
              <button
                type="button"
                :class="{ on: maklendPeriod === 'week' }"
                @click="maklendPeriod = 'week'"
              >
                1 неделя
              </button>
              <button
                type="button"
                :class="{ on: maklendPeriod === 'month' }"
                @click="maklendPeriod = 'month'"
              >
                1 месяц
              </button>
            </div>
          </div>
          <p class="hint plan-period-hint">
            Ввод в шт/{{ maklendPeriodLabel }}; в базе хранится шт/день
            (неделя = {{ WORK_DAYS_WEEK }} раб.дня, месяц =
            {{ maklendWorkDaysMonth }} раб.дней).
          </p>
          <div class="firm-prod-head firm-prod-row maklend-prod">
            <span></span>
            <span>товар</span>
            <span :title="'Теоретически шт/' + maklendPeriodLabel">план</span>
            <span :title="'Факт шт/' + maklendPeriodLabel">факт</span>
            <span>{{ maklendAltLabel }}</span>
            <span></span>
          </div>
          <div v-for="(prod, i) in maklendForm.products" :key="i" class="firm-prod-row maklend-prod">
            <button
              type="button"
              class="firm-prod-info"
              :disabled="!prod.sku_key"
              title="Открыть карточку товара"
              @click="openFirmProduct(prod.sku_key)"
            >
              i
            </button>
            <SkuPick
              class="firm-sku-pick"
              v-model="prod.sku_key"
              :options="firmProductOptions"
              placeholder="продукт…"
              @pick="(row) => onMaklendProductPick(row, prod)"
            />
            <input
              :value="qtyToPeriod(prod.qty_day) ?? ''"
              type="number"
              step="0.01"
              min="0"
              placeholder="план"
              :title="'Теоретически шт/' + maklendPeriodLabel"
              @input="setMaklendPlan(prod, $event.target.value)"
            />
            <input
              :value="qtyToPeriod(prod.qty_fact) ?? ''"
              type="number"
              step="0.01"
              min="0"
              placeholder="факт"
              :title="'По факту шт/' + maklendPeriodLabel"
              @input="setMaklendFact(prod, $event.target.value)"
            />
            <span class="qty-month" :title="maklendAltLabel">
              {{ maklendAltQty(prod.qty_day) ?? '—' }}
            </span>
            <button type="button" class="ghost" @click="removeMaklendProduct(i)">×</button>
          </div>
          <p v-if="maklendQtyPeriodTotal != null" class="ing-total">
            Итого: <b>{{ maklendQtyPeriodTotal }}</b> шт/{{ maklendPeriodLabel }}
            <template v-if="maklendPeriod !== 'day'">
              · <b>{{ maklendQtyDayTotal }}</b> шт/день
            </template>
            <template v-if="maklendPeriod !== 'month'">
              ·
              <b>{{
                maklendQtyDayTotal != null
                  ? ceil2(maklendQtyDayTotal * maklendWorkDaysMonth)
                  : '—'
              }}</b>
              шт/мес
            </template>
          </p>
          <div class="actions">
            <button type="button" class="ghost" @click="addMaklendProduct">+ продукт</button>
            <button type="button" class="primary" :disabled="busy" @click="saveMaklendFirm">
              Сохранить
            </button>
          </div>
        </div>
        <p v-else class="hint">Выберите фирму слева</p>
      </div>
      </template>
    </div>

    <template v-else>
      <div class="toolbar">
        <button type="button" class="primary" :disabled="busy" @click="startNew">+ Новый</button>
        <button type="button" class="ghost danger" :disabled="busy" @click="reseed">Перезалить seed</button>
      </div>

      <div v-if="formOpen" class="box form-box">
        <h2>{{ editId ? `Правка #${editId}` : 'Добавить' }}</h2>
        <div class="grid3">
          <label class="span2">
            Название
            <input v-model="form.name_ru" @input="onNameInput" />
            <span class="sku-slug" :title="skuKeyLocked ? 'ключ зафиксирован' : 'авто из названия'">
              {{ form.sku_key || 'ключ появится из названия' }}
            </span>
          </label>
          <label>
            Тип
            <KindPick v-model="form.kind" :options="KINDS" />
          </label>
          <label>
            Кто делает
            <FirmPick v-model="form.producer_code" :options="producers" />
          </label>
          <label>
            Ед.
            <UnitPick v-model="form.unit" :options="unitOptions" @add="onAddUnit" />
          </label>
          <label>Метка (цифра) <input v-model="form.icon_mark" placeholder="1, 2, 5…" /></label>
          <label>
            Форма
            <select v-model="form.shape" @change="onShapeChange">
              <option v-for="s in SHAPES" :key="s.key" :value="s.key">{{ s.ru }}</option>
            </select>
            <button
              v-if="form.shape && form.shape !== 'none'"
              type="button"
              class="linkish shape-edit"
              @click="openShapeModal"
            >
              {{ shapeSummary() }}
            </button>
          </label>
          <label>Вес, кг <input v-model.number="form.weight_kg" type="number" step="0.01" /></label>
        </div>

        <div v-if="form.kind === 'building'" class="box build-box">
          <h3>Калькулятор сруба</h3>
          <p class="hint">
            Куб {{ CUBE_HEIGHT_CM }} см · модуль бревна 100×15×15 · потолок ≈ 7 рядов (105 см).
            Внешние габариты → схема комнат, сырьё, труд, затем цена (маржа+НДС).
          </p>
          <div class="grid3">
            <label>
              Ширина снаружи, см
              <input v-model.number="form.width_cm" type="number" step="1" min="0" placeholder="300" />
            </label>
            <label>
              Длина (глубина), см
              <input v-model.number="form.length_cm" type="number" step="1" min="0" placeholder="500" />
            </label>
            <label>
              Высота стен, см
              <input v-model.number="form.height_cm" type="number" step="1" min="0" placeholder="105" />
            </label>
            <label>
              Перегородки
              <input v-model.number="form.build_partitions" type="number" step="1" min="0" />
            </label>
            <label>
              Окна
              <input v-model.number="form.build_windows" type="number" step="1" min="0" />
            </label>
            <label>
              Двери / проёмы
              <input v-model.number="form.build_doors" type="number" step="1" min="0" />
            </label>
          </div>
          <pre v-if="buildingPreview?.ok" class="build-scheme">{{ buildingSchemeText(buildingPreview) }}</pre>
          <p v-else-if="buildingPreview && !buildingPreview.ok" class="hint warn">{{ buildingPreview.error }}</p>
          <div class="actions">
            <button type="button" class="primary" :disabled="busy" @click="applyBuildingScheme">
              Применить схему → сырьё + цена
            </button>
          </div>
        </div>

        <div v-if="shapeOpen" class="modal-backdrop" @click.self="cancelShapeDims">
          <div class="modal" role="dialog" aria-label="Размеры">
            <h3>Размеры — {{ shapeLabel(form.shape) }}</h3>
            <div v-if="form.shape === 'cylinder'" class="grid2">
              <label>
                Диаметр, см
                <input v-model.number="shapeDraft.diameter_cm" type="number" step="0.01" min="0" />
              </label>
              <label>
                Высота, см
                <input v-model.number="shapeDraft.height_cm" type="number" step="0.01" min="0" />
              </label>
            </div>
            <div v-else class="grid2">
              <label>
                Длина, см
                <input v-model.number="shapeDraft.length_cm" type="number" step="0.01" min="0" />
              </label>
              <label>
                Ширина, см
                <input v-model.number="shapeDraft.width_cm" type="number" step="0.01" min="0" />
              </label>
              <label>
                Высота, см
                <input v-model.number="shapeDraft.height_cm" type="number" step="0.01" min="0" />
              </label>
            </div>
            <div class="grid2">
              <label>
                Плотность, г/см³
                <input
                  v-model.number="shapeDraft.density"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="вода 1, глина ~2"
                />
              </label>
              <label>
                Из справочника
                <div class="inline-actions">
                  <select v-model="densityPick" class="dens-select" @change="applyDensityPick">
                    <option value="">материал…</option>
                    <option v-for="d in densityItems" :key="d.key" :value="d.key">
                      {{ d.name_ru }} ({{ d.density }})
                    </option>
                  </select>
                  <button
                    type="button"
                    class="ghost"
                    title="подставить по названию / виду"
                    @click="applySuggestedDensity"
                  >
                    ↘
                  </button>
                </div>
              </label>
            </div>
            <p v-if="form.shape === 'box' || form.shape === 'cylinder'" class="hint shape-calc">
              Объём: {{ shapeDraftVolumeL != null ? shapeDraftVolumeL + ' л' : '—' }}
              · Вес: {{ shapeDraftWeightKg != null ? shapeDraftWeightKg + ' кг' : '—' }}
              <span v-if="shapeDraftWeightKg != null">(запишется в «Вес» по «Готово»)</span>
            </p>
            <p v-else class="hint shape-calc">Для «другое» вес вводится вручную.</p>
            <div class="actions">
              <button type="button" class="primary" @click="applyShapeDims">Готово</button>
              <button type="button" class="ghost" @click="cancelShapeDims">Отмена</button>
            </div>
          </div>
        </div>

        <h3>Труд</h3>
        <div class="grid3">
          <label>Труд, мин / чел. / ед. <input v-model.number="form.labor_min" type="number" step="0.01" min="0" /></label>
          <label>
            Рабочих
            <input v-model.number="form.labor_workers" type="number" step="1" min="1" placeholder="1" />
          </label>
          <label>
            % от смены (на 1 чел.)
            <input v-model.number="form.shift_pct" type="number" step="1" min="0" max="100" placeholder="100" />
          </label>
        </div>
        <div class="grid3">
          <label>
            ФОТ / ед.
            <input :value="laborCostPreview ?? ''" type="number" readonly class="readonly-field" />
          </label>
        </div>
        <p class="hint">
          ФОТ / ед. = ФОТ {{ FOT_MONTH }} × рабочих × % смены ÷ произв. в месяц
          (мин в месяце ÷ труд × {{ Math.round((Number(fiscalForm.util_rate) || 0.9) * 100) }}%).
          <span v-if="crewShiftHint">
            Эквив. бригады: {{ crewShiftHint.workers }}×{{ Math.round((Number(form.labor_min) / (Number(fiscalForm.labor_min_day) || 300)) * 100) }}%
            ≈ {{ crewShiftHint.pct }}% смены.
          </span>
        </p>

        <h3>Цены</h3>
        <p class="hint price-hint">
          Здание / инструмент / проект·наука: себест → маржа
          {{ Math.round(fiscalForm.producer_margin_rate * 100) }}% → НДС
          {{ Math.round(fiscalForm.vat_rate * 100) }}% (без магазина).
          Услуга: себест → маржа (пока без НДС).
          Сырьё / конечный / тара: ещё × маржа маг.
          {{ Math.round(fiscalForm.shop_margin_rate * 100) }}%. «Внутрь» / порция — только себест. Жмите «Пересчитать».
        </p>

        <h4 class="subh">Амортизация инструмента</h4>
        <div class="grid3 amort-row">
          <label>
            Инструмент
            <SkuPick
              v-model="amortDraft.tool_sku_key"
              :options="toolOptions"
              placeholder="выберите инструмент…"
            />
          </label>
          <label>
            Ресурс, исп.
            <input v-model.number="amortDraft.life_uses" type="number" step="1" min="1" />
          </label>
          <label>
            Аморт / ед.
            <div class="inline-actions">
              <input v-model.number="form.cost_amort" type="number" step="0.01" />
              <button type="button" class="ghost" @click="applyAmortFromTool">↘</button>
            </div>
          </label>
        </div>

        <h4 class="subh">Транспорт / тара</h4>
        <div class="grid3">
          <label>
            Тара
            <SkuPick
              v-model="taraDraft.pack_sku_key"
              :options="packOptions"
              placeholder="горшок, бочка…"
              @pick="onPackPick"
            />
          </label>
          <label>
            Ед. в тару
            <div class="inline-actions">
              <input
                v-model.number="form.pack_qty"
                type="number"
                step="1"
                min="0"
                placeholder="сколько шт в 1 таре"
              />
              <button
                type="button"
                class="ghost"
                title="посчитать по размерам товара и тары"
                @click="applyPackQtyFromDims"
              >
                ↘
              </button>
            </div>
          </label>
          <label>
            Ед. в пачке
            <input
              v-model.number="form.contents_qty"
              type="number"
              step="1"
              min="0"
              placeholder="60 листов, 10 яиц, 120 л…"
              title="Сколько расходных единиц внутри этой пачки/бочки (для расщепления)"
            />
          </label>
          <label>
            Тара / ед.
            <div class="inline-actions">
              <input v-model.number="form.cost_tara" type="number" step="0.01" />
              <button type="button" class="ghost" title="цена тары ÷ ед. в тару" @click="applyTaraFromSku()">
                ↘
              </button>
            </div>
          </label>
        </div>
        <p v-if="taraDraft.pack_sku_key" class="hint tara-fit">
          <template v-if="packFitPreview?.ok">
            По полу (без высоты): {{ dimsLabel(packFitPreview.itemDims, { floorOnly: true }) }} в
            {{ dimsLabel(packFitPreview.packDims, { floorOnly: true }) }} →
            <b>{{ packFitPreview.qty }}</b>
            ({{ packFitPreview.layout.join('×') }})
          </template>
          <template v-else>
            {{ packFitPreview?.reason || 'выберите тару с размерами' }}
          </template>
        </p>
        <div class="grid3">
          <label>
            Транспорт
            <SkuPick
              v-model="taraDraft.transport_sku_key"
              :options="transportOptions"
              placeholder="перевозка, тележка…"
              @pick="onTransportPick"
            />
          </label>
          <label>
            Транспортировок / день
            <div class="inline-actions">
              <input
                v-model.number="form.transport_trips_day"
                type="number"
                step="0.01"
                min="0"
                placeholder="рейсов за день"
              />
              <button
                type="button"
                class="ghost"
                title="(цена × рейсы) ÷ выработка/день"
                @click="applyTransportPerUnit"
              >
                ↘
              </button>
            </div>
          </label>
          <label>
            Транспорт / ед.
            <input
              v-model.number="form.cost_transport"
              type="number"
              step="0.01"
              readonly
              class="readonly-field"
              title="считается из цены транспорта и числа рейсов"
            />
          </label>
        </div>
        <p v-if="taraDraft.transport_sku_key" class="hint tara-fit">
          <template v-if="transportTripPrice != null && form.transport_trips_day > 0 && prodPerDay()">
            {{ transportTripPrice }} × {{ form.transport_trips_day }} /
            {{ ceil2(prodPerDay()) }} ед./день
            → {{ ceil2((transportTripPrice * form.transport_trips_day) / prodPerDay()) }} / ед.
          </template>
          <template v-else-if="transportTripPrice == null">
            у выбранного транспорта нет цены
          </template>
          <template v-else-if="!(form.labor_min > 0)">
            укажите труд, мин — нужна выработка за день
          </template>
          <template v-else>укажите число транспортировок в день</template>
          <template v-if="form.transport_name"> · {{ form.transport_name }}</template>
        </p>

        <div class="grid2 price-row">
          <label>Себестоимость <input v-model.number="form.cost" type="number" step="0.01" /></label>
          <label>
            Магазин
            <input
              v-model.number="form.price_shop"
              type="number"
              step="0.01"
              :disabled="['internal', 'service', 'tool', 'portion', 'project', 'building'].includes(form.kind)"
            />
          </label>
        </div>
        <div class="actions price-actions">
          <button type="button" class="ghost" :disabled="busy" @click="recalcPrices">Пересчитать</button>
          <span v-if="recalcNote" class="recalc-note">{{ recalcNote }}</span>
        </div>

        <h4 class="subh">Расход из пачки / бочки магазина</h4>
        <p class="hint">
          Купили пачку в магазине — считаем себест. 1 листа / яйца / литра = цена пачки ÷ N.
          Тип станет <b>порция</b> (не витрина магазина). То же для яиц и бочек с водой.
        </p>
        <div class="grid3">
          <label>
            Пачка / бочка
            <SkuPick
              v-model="unpackDraft.pack_sku_key"
              :options="unpackPackOptions"
              placeholder="пачка 60 л., десяток, бочка…"
              @pick="onUnpackPackPick"
            />
          </label>
          <label>
            Ед. внутри
            <input
              v-model.number="unpackDraft.contents_qty"
              type="number"
              step="1"
              min="1"
              placeholder="60"
            />
          </label>
          <label>
            Цена пачки
            <div class="inline-actions">
              <select v-model="unpackDraft.use_shop" class="dens-select">
                <option :value="true">магазин</option>
                <option :value="false">себест.</option>
              </select>
              <button type="button" class="ghost" title="проставить рецепт 1/N и себест." @click="applyUnpackFromShop">
                ↘
              </button>
            </div>
          </label>
        </div>

        <h3>Рецепт</h3>
        <template v-for="(ing, i) in form.ingredients" :key="i">
          <div class="ing-row">
            <SkuPick
              v-model="ing.ingredient_sku_key"
              :options="ingredientOptions"
              @pick="(row) => onIngredientPick(ing, row)"
            />
            <input v-model.number="ing.qty" type="number" step="0.0001" placeholder="кол-во" />
            <button type="button" class="ghost" @click="removeIngredient(i)">×</button>
          </div>
          <p v-if="ingInfo(ing)" class="ing-info">
            <template v-if="ingInfo(ing).price != null">
              {{ ingInfo(ing).price.toFixed(2) }} / {{ ingInfo(ing).unit || 'ед.' }}
              <span class="muted">({{ ingInfo(ing).src }})</span>
              <template v-if="ingInfo(ing).sum != null">
                × {{ ing.qty }} = <b>{{ ingInfo(ing).sum.toFixed(2) }}</b>
              </template>
            </template>
            <span v-else class="warn">нет цены</span>
            <template v-if="ingInfo(ing).weight != null">
              · вес {{ ingInfo(ing).weight }} кг
            </template>
            <template v-else-if="ingInfo(ing).unitWeight == null">
              · <span class="muted">вес не задан</span>
            </template>
            <template v-if="ingInfo(ing).kind"> · {{ ingInfo(ing).kind }}</template>
            <template v-if="ingInfo(ing).firm"> · {{ ingInfo(ing).firm }}</template>
          </p>
        </template>
        <p v-if="ingTotals.sum != null || ingTotals.weight != null" class="ing-total">
          Итого по рецепту:
          <template v-if="ingTotals.sum != null"><b>{{ ingTotals.sum.toFixed(2) }}</b></template>
          <template v-if="ingTotals.weight != null"> · вес {{ ingTotals.weight }} кг</template>
        </p>
        <button type="button" class="ghost" @click="addIngredient">+ ингредиент</button>

        <label class="notes">Заметки <textarea v-model="form.notes" rows="2" /></label>

        <div class="actions">
          <button type="button" class="primary" :disabled="busy" @click="saveSku">
            {{ editId ? 'Сохранить' : 'Создать' }}
          </button>
          <button
            v-if="editId"
            type="button"
            class="ghost"
            :disabled="busy"
            title="Создать новый SKU с теми же полями"
            @click="copySku"
          >
            Копировать товар
          </button>
          <button type="button" class="ghost" @click="closeForm">Отмена</button>
          <button
            v-if="editId"
            type="button"
            class="ghost danger"
            :disabled="busy"
            @click="archiveSku"
          >
            В архив
          </button>
          <span v-if="form.icon_key || form.sku_key" class="preview">
            <CivIcon
              :name="form.icon_key || skuIcon(form.sku_key)"
              :mark="form.icon_mark"
              :shop="form.kind === 'good' || form.kind === 'pack' || (form.kind === 'raw' && form.price_shop != null)"
              :tint-key="form.sku_key"
              :sku-key="form.sku_key"
              :size="56"
            />
          </span>
        </div>
      </div>

      <div class="box">
        <h2>Список ({{ filteredItems.length }})</h2>
        <table class="tbl">
          <thead>
            <tr>
              <th></th>
              <th>
                <div class="th-stack">
                  <button type="button" class="th-sort" @click="toggleSort('name')">
                    Название{{ sortMark('name') }}
                  </button>
                  <input
                    v-model="q"
                    class="th-filter th-name"
                    type="search"
                    placeholder="поиск…"
                    title="Поиск по названию"
                  />
                </div>
              </th>
              <th>
                <div class="th-stack">
                  <button type="button" class="th-sort" @click="toggleSort('kind')" title="Сортировка по типу">
                    Тип{{ sortMark('kind') }}
                  </button>
                  <select v-model="kindFilter" class="th-filter" title="Фильтр по типу">
                    <option value="">все</option>
                    <option v-for="k in KINDS" :key="k.key" :value="k.key">{{ k.ru }}</option>
                  </select>
                </div>
              </th>
              <th>
                <div class="th-stack">
                  <button type="button" class="th-sort" @click="toggleSort('producer')">
                    Фирма{{ sortMark('producer') }}
                  </button>
                  <select v-model="producer" class="th-filter" title="Фильтр по фирме">
                    <option value="">все</option>
                    <option v-for="p in producers" :key="p" :value="p">{{ p }}</option>
                  </select>
                </div>
              </th>
              <th>
                <div class="th-stack">
                  <button type="button" class="th-sort" @click="toggleSort('price')">
                    Цены{{ sortMark('price') }}
                  </button>
                  <select v-model="priceFilter" class="th-filter" title="Фильтр по цене">
                    <option value="">все</option>
                    <option value="has">с ценой</option>
                    <option value="none">без цены</option>
                  </select>
                  <div class="th-range">
                    <input
                      v-model.number="priceMin"
                      type="number"
                      step="0.01"
                      placeholder="от"
                      class="th-filter"
                    />
                    <input
                      v-model.number="priceMax"
                      type="number"
                      step="0.01"
                      placeholder="до"
                      class="th-filter"
                    />
                  </div>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filteredItems" :key="row.id">
              <td class="ico">
                <CivIcon
                  :name="rowVisual(row).name"
                  :mark="rowVisual(row).mark"
                  :shop="rowVisual(row).shop"
                  :tint-key="rowVisual(row).tintKey"
                  :sku-key="row.sku_key"
                  :size="48"
                />
              </td>
              <td>
                <button type="button" class="linkish" @click="openEdit(row.id)">{{ row.name_ru }}</button>
              </td>
              <td class="cell-icon" :title="kindRu(row.kind)">
                <CivIcon :name="kindIcon(row.kind)" :label="kindRu(row.kind)" :size="28" />
              </td>
              <td class="cell-firm" :title="row.producer_code || ''">
                <img
                  v-if="firmLogoSrc(row.producer_code)"
                  class="firm-logo"
                  :src="firmLogoSrc(row.producer_code)"
                  :alt="row.producer_code"
                />
                <span v-else class="muted">—</span>
              </td>
              <td>{{ priceLabel(row) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.panel {
  max-width: 1100px;
  margin: 0 auto;
  padding: 1.25rem 1rem 3rem;
  color: #1a2b22;
}
.head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.back {
  color: #3d6b52;
  text-decoration: none;
  font-size: 0.9rem;
}
h1 {
  margin: 0.2rem 0;
  font-size: 1.6rem;
}
.lead {
  margin: 0.35rem 0 0;
  color: #4a5c52;
  max-width: 40rem;
  line-height: 1.4;
}
.who {
  color: #5a6b62;
}
.err {
  color: #8b1e1e;
  background: #fde8e8;
  padding: 0.5rem 0.75rem;
  border-radius: 6px;
}
.ok {
  color: #1e5b34;
  background: #e4f5ea;
  padding: 0.5rem 0.75rem;
  border-radius: 6px;
}
.tabs {
  display: flex;
  gap: 0.4rem;
  margin: 0.75rem 0 1rem;
}
.tabs button {
  border: 1px solid #c5d4ca;
  background: #f4f7f5;
  padding: 0.4rem 0.85rem;
  border-radius: 6px;
  cursor: pointer;
}
.tabs button.on {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
}
.box {
  background: #f7faf8;
  border: 1px solid #d5e0d8;
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}
.form-box h3 {
  margin: 1rem 0 0.4rem;
  font-size: 0.95rem;
  color: #2f5d3a;
}
.build-box {
  margin-top: 0.75rem;
  background: #eef6f0;
}
.build-box h3 {
  margin-top: 0;
}
.build-scheme {
  margin: 0.55rem 0 0.35rem;
  padding: 0.55rem 0.65rem;
  background: #fff;
  border: 1px dashed #b7c9bc;
  border-radius: 6px;
  font-size: 0.78rem;
  line-height: 1.4;
  white-space: pre-wrap;
  color: #2a3d32;
}
.builds-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.86rem;
  margin-top: 0.75rem;
}
.builds-table th,
.builds-table td {
  text-align: left;
  padding: 0.45rem 0.4rem;
  border-bottom: 1px solid #d5e0d8;
  vertical-align: top;
}
.builds-table .muted {
  color: #6a7b72;
  font-size: 0.78rem;
}
.actions-cell {
  white-space: nowrap;
}
.hint.warn {
  color: #8a3a2b;
}
.hint {
  color: #5a6b62;
  font-size: 0.9rem;
}
.price-hint {
  margin: 0 0 0.55rem;
  line-height: 1.35;
}
.subh {
  margin: 0.75rem 0 0.35rem;
  font-size: 0.88rem;
  color: #2f5d3a;
  font-weight: 600;
}
.tara-fit {
  margin: 0.25rem 0 0.5rem;
}
.inline-actions {
  display: flex;
  gap: 0.35rem;
  align-items: center;
}
.inline-actions input {
  flex: 1;
  min-width: 0;
}
.amort-row {
  margin-bottom: 0.35rem;
}
.muted-field input {
  color: #5a6b62;
  background: #f4f7f5;
}
.readonly-field {
  color: #5a6b62;
  background: #f4f7f5;
  cursor: default;
}
.price-actions {
  margin-top: 0.5rem;
}
.recalc-note {
  font-size: 0.8rem;
  color: #4a5c52;
  line-height: 1.3;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}
.grid3 {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.55rem 0.75rem;
}
.span2 {
  grid-column: span 2;
}
.dens-row {
  display: grid;
  grid-template-columns: 7rem 1fr 5.5rem 1fr 1fr minmax(0, 1.2fr) auto;
  gap: 0.35rem;
  margin-bottom: 0.45rem;
  align-items: end;
}
.dens-del {
  margin-bottom: 0.15rem;
}
.firm-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.35rem;
}
.firm-subtabs {
  display: flex;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
}
.plan-period-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin: 0.85rem 0 0.25rem;
}
.plan-period-row h3 {
  margin: 0;
}
.plan-period-tabs {
  margin-bottom: 0;
}
.plan-period-hint {
  margin: 0 0 0.45rem;
}
.firm-subtabs button {
  border: 1px solid #c5d4c8;
  background: #f4f8f4;
  color: #2f5d3a;
  border-radius: 8px;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  font: inherit;
}
.firm-subtabs button.on {
  background: #2f5d3a;
  color: #fffcf5;
  border-color: #2f5d3a;
}
.firm-list-btn.over {
  outline: 1px solid #c45c4a;
}
.cap-line {
  margin: 0.65rem 0 0.25rem;
  font-size: 0.9rem;
  color: #2f5d3a;
}
.cap-line.over,
.warn-text {
  color: #a33b2c;
}
.maklend-prod {
  /* колонки задаются в .firm-prod-row.maklend-prod — иначе перебьёт 4-колоночный шаблон */
}
.firm-prod-head {
  margin-bottom: 0.2rem;
  font-size: 0.7rem;
  color: #6a7d72;
  text-transform: lowercase;
}
.firm-prod-head.firm-prod-row {
  margin-bottom: 0.2rem;
}
.qty-month {
  font-size: 0.75rem;
  color: #6a7d72;
  white-space: nowrap;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
}
.firm-sku-pick {
  max-width: 100%;
  min-width: 0;
  width: 100%;
  overflow: hidden;
}
.firm-head h2 {
  margin: 0 auto 0 0;
}
.firm-layout {
  display: grid;
  grid-template-columns: minmax(11rem, 15rem) minmax(0, 1fr);
  gap: 1rem;
  margin-top: 0.75rem;
  align-items: start;
}
.firm-list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 70vh;
  overflow: auto;
  border: 1px solid #c5d4cb;
  border-radius: 8px;
  background: #f7faf8;
}
.firm-list-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  border: none;
  border-bottom: 1px solid #e0eae4;
  background: transparent;
  padding: 0.45rem 0.55rem;
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.firm-list-btn:hover,
.firm-list-btn.on {
  background: #e8f2eb;
}
.firm-list-meta {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}
.firm-list-meta small {
  color: #5a6b62;
  font-size: 0.75rem;
}
.firm-card h3 {
  margin: 1rem 0 0.4rem;
  font-size: 0.95rem;
  color: #2f5d3a;
}
.firm-prod-row {
  display: grid;
  grid-template-columns: 1.6rem minmax(0, 1fr) 5rem 1.6rem;
  gap: 0.35rem;
  margin-bottom: 0.35rem;
  align-items: center;
}
.firm-prod-row.maklend-prod {
  grid-template-columns: 1.6rem minmax(0, 1fr) 4.25rem 4.25rem 3rem 1.6rem;
}
.firm-prod-info {
  width: 1.45rem;
  height: 1.45rem;
  padding: 0;
  border: 1.5px solid #1f3d28;
  border-radius: 999px;
  background: #fffcf5;
  color: #1f3d28;
  font: 700 0.78rem/1 Georgia, 'Times New Roman', serif;
  font-style: italic;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.firm-prod-info:disabled {
  opacity: 0.35;
  cursor: default;
}
.firm-prod-row :deep(.sku-pick) {
  min-width: 0;
  max-width: 100%;
  width: 100%;
}
.firm-prod-row :deep(.sku-pick-btn) {
  min-height: 2.1rem;
  padding: 0.2rem 0.35rem;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}
.firm-prod-row :deep(.sku-pick-label) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.firm-prod-row input[type='number'] {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}
.firm-prod-row > .ghost {
  width: 1.6rem;
  min-width: 1.6rem;
  padding: 0.2rem 0;
  justify-self: center;
}
@media (max-width: 800px) {
  .firm-layout {
    grid-template-columns: 1fr;
  }
}
.dens-select {
  flex: 1;
  min-width: 0;
}
@media (max-width: 960px) {
  .dens-row {
    grid-template-columns: 1fr 1fr;
  }
}
.sku-slug {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.78rem;
  color: #8a9b92;
  font-family: ui-monospace, Consolas, monospace;
  word-break: break-all;
}
.shape-edit {
  display: block;
  margin-top: 0.25rem;
  font-size: 0.8rem;
}
.grid2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem 0.75rem;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgba(26, 43, 34, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal {
  background: #f7faf8;
  border: 1px solid #d5e0d8;
  border-radius: 10px;
  padding: 1rem 1.1rem 1.1rem;
  width: min(420px, 100%);
  box-shadow: 0 12px 40px rgba(26, 43, 34, 0.18);
}
.modal h3 {
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
  color: #2f5d3a;
}
.shape-calc {
  margin: 0.6rem 0 0;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.82rem;
  color: #3d4f45;
}
label.check {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  margin-top: 1.4rem;
}
input,
select,
textarea {
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.35rem 0.45rem;
  font: inherit;
  background: #fff;
}
.notes {
  margin-top: 0.75rem;
}
.ing-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 5.5rem auto;
  gap: 0.35rem;
  margin-bottom: 0.35rem;
  align-items: center;
}
.ing-info {
  margin: -0.15rem 0 0.5rem 0.2rem;
  font-size: 0.78rem;
  color: #4a5c52;
}
.ing-info .warn {
  color: #a0522d;
}
.ing-total {
  margin: 0.2rem 0 0.5rem;
  font-size: 0.85rem;
  color: #2f5d3a;
}
.actions {
  margin-top: 0.9rem;
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.preview {
  margin-left: 0.5rem;
}
button.primary {
  background: #2f5d3a;
  color: #fff;
  border: none;
  padding: 0.45rem 0.9rem;
  border-radius: 6px;
  cursor: pointer;
}
button.ghost {
  background: transparent;
  border: 1px solid #b8c9be;
  padding: 0.4rem 0.7rem;
  border-radius: 6px;
  cursor: pointer;
}
button.danger {
  border-color: #c99;
  color: #8b1e1e;
}
button.linkish {
  background: none;
  border: none;
  color: #1e5b34;
  cursor: pointer;
  padding: 0;
  font: inherit;
  text-align: left;
  text-decoration: underline;
}
.muted {
  font-size: 0.75rem;
  color: #7a8b82;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}
.tbl th,
.tbl td {
  border-bottom: 1px solid #dde6e0;
  padding: 0.45rem 0.35rem;
  text-align: left;
  vertical-align: middle;
}
.th-sort {
  border: none;
  background: none;
  padding: 0;
  font: inherit;
  font-weight: 600;
  color: #1a2b22;
  cursor: pointer;
}
.th-sort:hover {
  color: #2f5d3a;
}
.th-stack {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  align-items: flex-start;
}
.th-filter {
  font-size: 0.75rem;
  padding: 0.2rem 0.3rem;
  max-width: 7.5rem;
}
.th-name {
  max-width: 11rem;
  width: 100%;
}
.th-range {
  display: flex;
  gap: 0.2rem;
}
.th-range .th-filter {
  max-width: 3.6rem;
}
.tbl td.ico {
  width: 64px;
}
.tbl td.cell-icon {
  width: 44px;
  text-align: center;
}
.tbl td.cell-firm {
  width: 52px;
}
.firm-logo {
  width: 40px;
  height: 40px;
  object-fit: contain;
  border-radius: 6px;
  display: block;
}
@media (max-width: 800px) {
  .grid3,
  .ing-row {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
