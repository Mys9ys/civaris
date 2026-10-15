<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '../api'
import { FIRM_CODES, firmLogoSrc } from '../icons/firmLogo'
import FirmPick from './FirmPick.vue'
import SettlementPlotGfx from './SettlementPlotGfx.vue'

const props = defineProps({
  layout: { type: Object, default: null },
  worldId: { type: [String, Number], required: true },
  settlementId: { type: [String, Number], required: true },
})

const emit = defineEmits(['updated', 'error'])

const open = ref(false)
const zoom = ref(0.5)
const panX = ref(0)
const panY = ref(0)
const dragging = ref(false)
const dragOrigin = ref({ x: 0, y: 0, panX: 0, panY: 0 })

const showGrid = ref(true)
const gridStep = ref(5) // 1 | 5 | 10
const busy = ref(false)
const selectedId = ref(null)
const moveMode = ref(false)
/** Перетаскивание здания мышкой: { id, grabDx, grabDy } */
const plotDrag = ref(null)
const viewportEl = ref(null)
const mapSvgEl = ref(null)

const localPlots = ref([])
const templates = ref([])

const draftFirm = ref({ title: '', width_m: 6, depth_m: 5 })
const placing = ref(null) // template being placed with ghost
const ghost = ref({ x_m: 2, y_m: 2, rotation_deg: 0 })
/** Перетаскивание шаблона из правого боковика на карту */
const paletteDragging = ref(false)
/** Правка ширины/глубины площадки в стройке (магазин / фирма без плана). */
const sizeEditOpen = ref(false)
const draftSize = ref({ width_m: 6, depth_m: 5 })

const MIN_ZOOM = 0.35
const MAX_ZOOM = 5
const ZOOM_STEP = 0.25
const DEFAULT_ZOOM = 0.5

/** Шаг расширения холста: сетка × множитель (×1 / ×5 / ×10). */
const expandMult = ref(1)

const originX = computed(() =>
  Number(props.layout?.origin_x_m ?? (Number(props.layout?.map_width_m) || 80) / 2)
)
const originY = computed(() =>
  Number(props.layout?.origin_y_m ?? (Number(props.layout?.map_height_m) || 60) / 2)
)

const mapMinX = computed(() => Number(props.layout?.map_min_x_m ?? 0))
const mapMinY = computed(() => Number(props.layout?.map_min_y_m ?? 0))
const mapMaxX = computed(() => {
  return (
    Number(props.layout?.map_max_x_m) ||
    Number(props.layout?.map_width_m) ||
    80
  )
})
const mapMaxY = computed(() => {
  return (
    Number(props.layout?.map_max_y_m) ||
    Number(props.layout?.map_height_m) ||
    60
  )
})
const mapW = computed(() => Number((mapMaxX.value - mapMinX.value).toFixed(2)))
const mapH = computed(() => Number((mapMaxY.value - mapMinY.value).toFixed(2)))
const streetReserve = computed(
  () => Number(props.layout?.street_reserve_m ?? props.layout?.street_m) || 16
)
const expandMeters = computed(() =>
  Number((gridStep.value * expandMult.value).toFixed(2))
)
const expandLabel = computed(() => `+${expandMeters.value} м`)

/** Держать объект внутри текущего холста (без авто-роста). */
function clampTopLeft(x, y, w, d) {
  const ww = Number(w) || 1
  const dd = Number(d) || 1
  let maxX = mapMaxX.value - ww
  let maxY = mapMaxY.value - dd
  if (maxX < mapMinX.value) maxX = mapMinX.value
  if (maxY < mapMinY.value) maxY = mapMinY.value
  return {
    x_m: Number(Math.min(Math.max(x, mapMinX.value), maxX).toFixed(2)),
    y_m: Number(Math.min(Math.max(y, mapMinY.value), maxY).toFixed(2)),
  }
}

async function expandCanvasEdge(edge) {
  if (busy.value) return
  busy.value = true
  try {
    const res = await api.layoutCanvasExpand(props.worldId, props.settlementId, {
      edge,
      meters: expandMeters.value,
    })
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

/** Миниатюра шаблона для палитры. */
function miniFromTemplate(tpl) {
  const w = Number(tpl.width_m) || 6
  const d = Number(tpl.depth_m) || 5
  return {
    id: `mini-${tpl.key}`,
    kind: tpl.kind,
    title: tpl.title,
    width_m: w,
    depth_m: d,
    x_m: 0,
    y_m: 0,
    rotation_deg: 0,
    flip_x: 0,
    flip_y: 0,
    floor_plan: tpl.floor_plan || null,
    template_key: tpl.key,
    road_mode: tpl.road_mode || null,
    company_code: tpl.company || null,
    is_pinned: 0,
    occupied: tpl.capacity ? Math.min(Number(tpl.capacity), 10) : 0,
    capacity: tpl.capacity || 0,
  }
}

/** Миниатюра уже стоящего объекта (без мировых координат / поворота). */
function miniFromPlot(p) {
  const w = Number(p.width_m) || 6
  const d = Number(p.depth_m) || 5
  return {
    ...p,
    id: `placed-mini-${p.id}`,
    x_m: 0,
    y_m: 0,
    width_m: w,
    depth_m: d,
    rotation_deg: 0,
    flip_x: 0,
    flip_y: 0,
  }
}

function miniViewBox(tpl) {
  const w = Number(tpl.width_m) || 6
  const d = Number(tpl.depth_m) || 5
  const pad = Math.max(w, d) * 0.08 + 0.35
  return `${-pad} ${-pad} ${w + pad * 2} ${d + pad * 2}`
}

function selectPlaced(p) {
  if (placing.value || paletteDragging.value) return
  selectedId.value = p.id
  moveMode.value = false
}

const zoomLabel = computed(() => `${Math.round(zoom.value * 100)}%`)
const mapTransform = computed(
  () => `translate(${panX.value}px, ${panY.value}px) scale(${zoom.value})`
)

const selected = computed(() =>
  localPlots.value.find((p) => Number(p.id) === Number(selectedId.value)) || null
)

/** Непостроенные силуэты без плана комнат — можно менять габарит. */
const canResizeSelected = computed(() => {
  const p = selected.value
  if (!p || p.kind === 'road' || p.kind === 'dorm') return false
  if (p.build_status !== 'building') return false
  if (p.floor_plan?.elements?.length) return false
  return true
})

watch(selectedId, () => {
  sizeEditOpen.value = false
})

const roads = computed(() => localPlots.value.filter((p) => p.kind === 'road'))
const buildings = computed(() =>
  localPlots.value.filter(
    (p) => p.kind && p.kind !== 'street' && p.kind !== 'yard' && p.kind !== 'road'
  )
)
const mapItems = computed(() => [...roads.value, ...buildings.value])
const buildingTemplates = computed(() => templates.value.filter((t) => t.kind !== 'road'))
const roadTemplates = computed(() => templates.value.filter((t) => t.kind === 'road'))

const gridLines = computed(() => {
  const step = gridStep.value
  const ox = originX.value
  const oy = originY.value
  const xs = []
  const ys = []
  const x0 = Math.floor(mapMinX.value / step) * step
  const y0 = Math.floor(mapMinY.value / step) * step
  for (let x = x0; x <= mapMaxX.value + 0.01; x += step) {
    const abs = Number(x.toFixed(2))
    const rel = Number((abs - ox).toFixed(2))
    xs.push({ abs, rel, isOrigin: Math.abs(rel) < 0.001 })
  }
  for (let y = y0; y <= mapMaxY.value + 0.01; y += step) {
    const abs = Number(y.toFixed(2))
    const rel = Number((abs - oy).toFixed(2))
    ys.push({ abs, rel, isOrigin: Math.abs(rel) < 0.001 })
  }
  return { xs, ys, step }
})

const viewBox = computed(
  () =>
    `${mapMinX.value - 1} ${mapMinY.value - 1} ${mapW.value + 2} ${mapH.value + 2}`
)

/** Поставить здание так, чтобы центр совпал с нулём (или с точкой клика). */
function placeTopLeft(tplOrSize, at = null) {
  const w = Number(tplOrSize.width_m) || 6
  const d = Number(tplOrSize.depth_m) || 5
  if (at) {
    return { x_m: at.x_m - w / 2, y_m: at.y_m - d / 2 }
  }
  return { x_m: originX.value - w / 2, y_m: originY.value - d / 2 }
}

function plotBox(p) {
  return {
    x: Number(p.x_m) || 0,
    y: Number(p.y_m) || 0,
    w: Number(p.width_m) || 0,
    d: Number(p.depth_m) || 0,
  }
}

function boxesOverlap(a, b, pad = 0.05) {
  return (
    a.x + pad < b.x + b.w &&
    a.x + a.w > b.x + pad &&
    a.y + pad < b.y + b.d &&
    a.y + a.d > b.y + pad
  )
}

/** Здание не должно залезать на дорогу. */
function overlapsAnyRoad(box, ignoreId = null) {
  for (const r of roads.value) {
    if (ignoreId != null && Number(r.id) === Number(ignoreId)) continue
    if (boxesOverlap(box, plotBox(r))) return true
  }
  return false
}

/** Притянуть плитку дороги встык к соседней (как Лего). */
function snapRoadTopLeft(x, y, w, d, ignoreId = null) {
  const tol = 2.5
  let best = { x, y, dist: Infinity }
  for (const r of roads.value) {
    if (ignoreId != null && Number(r.id) === Number(ignoreId)) continue
    const rb = plotBox(r)
    const candidates = [
      { x: rb.x + rb.w, y: rb.y },
      { x: rb.x - w, y: rb.y },
      { x: rb.x, y: rb.y + rb.d },
      { x: rb.x, y: rb.y - d },
      { x: rb.x + rb.w, y: rb.y + rb.d - d },
      { x: rb.x - w, y: rb.y + rb.d - d },
      { x: rb.x + rb.w - w, y: rb.y + rb.d },
      { x: rb.x + rb.w - w, y: rb.y - d },
    ]
    for (const c of candidates) {
      const dist = Math.hypot(c.x - x, c.y - y)
      if (dist <= tol && dist < best.dist) {
        best = { x: c.x, y: c.y, dist }
      }
    }
  }
  return best.dist < Infinity
    ? { x_m: Number(best.x.toFixed(2)), y_m: Number(best.y.toFixed(2)) }
    : { x_m: Number(x.toFixed(2)), y_m: Number(y.toFixed(2)) }
}

function syncFromLayout() {
  localPlots.value = [...(props.layout?.plots || [])]
  templates.value = [...(props.layout?.templates || [])]
}

watch(
  () => props.layout,
  () => syncFromLayout(),
  { immediate: true, deep: true }
)

function clampZoom(z) {
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(z / ZOOM_STEP) * ZOOM_STEP))
}

function openModal() {
  open.value = true
  zoom.value = DEFAULT_ZOOM
  panX.value = 0
  panY.value = 0
  selectedId.value = null
  moveMode.value = false
  placing.value = null
}

function stopPaletteDragListeners() {
  window.removeEventListener('pointermove', onPaletteDragMove)
  window.removeEventListener('pointerup', onPaletteDragUp)
  window.removeEventListener('pointercancel', onPaletteDragUp)
  paletteDragging.value = false
}

function closeModal() {
  open.value = false
  dragging.value = false
  placing.value = null
  moveMode.value = false
  stopPaletteDragListeners()
}

function zoomBy(delta) {
  zoom.value = clampZoom(zoom.value + delta)
}

function resetView() {
  zoom.value = DEFAULT_ZOOM
  panX.value = 0
  panY.value = 0
}

function onWheel(e) {
  zoomBy(e.deltaY > 0 ? -ZOOM_STEP : ZOOM_STEP)
}

function onKey(e) {
  if (!open.value) return
  if (e.key === 'Escape') {
    if (placing.value) placing.value = null
    else if (moveMode.value) moveMode.value = false
    else closeModal()
  }
  if (e.key === '+' || e.key === '=') zoomBy(ZOOM_STEP)
  if (e.key === '-' || e.key === '_') zoomBy(-ZOOM_STEP)
  if (e.key === '0') resetView()
}

watch(open, (v) => {
  document.body.style.overflow = v ? 'hidden' : ''
})

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  stopPaletteDragListeners()
  document.body.style.overflow = ''
})

function applyLayout(layout) {
  if (!layout) return
  // Сразу в локальный список «На карте», не ждём round-trip props
  if (Array.isArray(layout.plots)) {
    localPlots.value = [...layout.plots]
  }
  if (Array.isArray(layout.templates)) {
    templates.value = [...layout.templates]
  }
  emit('updated', layout)
}

async function createFromTemplate(tpl, at = null) {
  busy.value = true
  try {
    const pos = placeTopLeft(tpl, at)
    const body = {
      template_key: tpl.key,
      x_m: pos.x_m,
      y_m: pos.y_m,
      rotation_deg: at?.rotation_deg ?? 0,
      title: tpl.title,
    }
    const res = await api.layoutPlotCreate(props.worldId, props.settlementId, body)
    applyLayout(res.layout)
    if (res.plot) selectedId.value = res.plot.id
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

async function createCustomFirm() {
  const title = draftFirm.value.title.trim() || 'Фирма'
  busy.value = true
  try {
    const size = {
      width_m: Number(draftFirm.value.width_m) || 6,
      depth_m: Number(draftFirm.value.depth_m) || 5,
    }
    const pos = placeTopLeft(size)
    const body = {
      template_key: 'custom_firm',
      title,
      width_m: size.width_m,
      depth_m: size.depth_m,
      x_m: pos.x_m,
      y_m: pos.y_m,
    }
    // Если имя совпало с кодом фирмы — сразу привязка
    if (firmBindOptions.includes(title)) {
      body.company_code = title
    }
    const res = await api.layoutPlotCreate(props.worldId, props.settlementId, body)
    applyLayout(res.layout)
    draftFirm.value.title = ''
    if (res.plot) selectedId.value = res.plot.id
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

function startPlace(tpl) {
  placing.value = tpl
  const pos = placeTopLeft(tpl)
  ghost.value = {
    x_m: pos.x_m,
    y_m: pos.y_m,
    rotation_deg: 0,
    width_m: tpl.width_m,
    depth_m: tpl.depth_m,
    kind: tpl.kind,
    title: tpl.title,
    floor_plan: tpl.floor_plan,
    template_key: tpl.key,
    road_mode: tpl.road_mode || null,
    company_code: tpl.company || null,
    is_pinned: 0,
  }
  moveMode.value = false
  selectedId.value = null
}

function screenToMap(evt, svgEl) {
  const svg = svgEl || evt.currentTarget
  const pt = svg.createSVGPoint()
  pt.x = evt.clientX
  pt.y = evt.clientY
  const ctm = svg.getScreenCTM()
  if (!ctm) return { x: 0, y: 0 }
  const loc = pt.matrixTransform(ctm.inverse())
  return { x: loc.x, y: loc.y }
}

function clientOverMap(clientX, clientY) {
  const svg = mapSvgEl.value
  if (!svg) return false
  const r = svg.getBoundingClientRect()
  return clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom
}

function updateGhostFromClient(clientX, clientY) {
  if (!placing.value || !mapSvgEl.value) return
  if (!clientOverMap(clientX, clientY)) return
  const { x, y } = screenToMap({ clientX, clientY }, mapSvgEl.value)
  let pos = placeTopLeft(placing.value, { x_m: x, y_m: y })
  if (placing.value.kind === 'road') {
    pos = snapRoadTopLeft(pos.x_m, pos.y_m, placing.value.width_m, placing.value.depth_m)
  }
  pos = clampTopLeft(
    pos.x_m,
    pos.y_m,
    Number(placing.value.width_m) || 16,
    Number(placing.value.depth_m) || 16
  )
  ghost.value = {
    ...ghost.value,
    x_m: pos.x_m,
    y_m: pos.y_m,
  }
}

async function commitPlaceAt(mapX, mapY) {
  if (!placing.value || busy.value) return false
  busy.value = true
  try {
    let pos
    const isRoad = placing.value.kind === 'road'
    if (isRoad) {
      pos = placeTopLeft(placing.value, { x_m: mapX, y_m: mapY })
      pos = snapRoadTopLeft(pos.x_m, pos.y_m, placing.value.width_m, placing.value.depth_m)
    } else {
      pos = placeTopLeft(placing.value, { x_m: mapX, y_m: mapY })
      const box = {
        x: pos.x_m,
        y: pos.y_m,
        w: Number(placing.value.width_m),
        d: Number(placing.value.depth_m),
      }
      if (overlapsAnyRoad(box)) {
        emit('error', 'Сюда нельзя: пересечение с дорогой')
        return false
      }
    }
    const res = await api.layoutPlotCreate(props.worldId, props.settlementId, {
      template_key: placing.value.key,
      x_m: Number(pos.x_m.toFixed(2)),
      y_m: Number(pos.y_m.toFixed(2)),
      rotation_deg: ghost.value.rotation_deg || 0,
      title: placing.value.title,
    })
    applyLayout(res.layout)
    if (res.plot) selectedId.value = res.plot.id
    placing.value = null
    return true
  } catch (err) {
    emit('error', err.message)
    return false
  } finally {
    busy.value = false
  }
}

/** Хват из правой панели → тащим на карту → отпускаем. */
function onPaletteGrab(e, tpl) {
  if (busy.value || e.button !== 0) return
  e.preventDefault()
  e.stopPropagation()
  startPlace(tpl)
  paletteDragging.value = true
  window.addEventListener('pointermove', onPaletteDragMove)
  window.addEventListener('pointerup', onPaletteDragUp)
  window.addEventListener('pointercancel', onPaletteDragUp)
}

function onPaletteDragMove(e) {
  if (!paletteDragging.value || !placing.value) return
  updateGhostFromClient(e.clientX, e.clientY)
}

async function onPaletteDragUp(e) {
  if (!paletteDragging.value) return
  stopPaletteDragListeners()
  if (!placing.value) return
  if (clientOverMap(e.clientX, e.clientY) && mapSvgEl.value) {
    const { x, y } = screenToMap({ clientX: e.clientX, clientY: e.clientY }, mapSvgEl.value)
    await commitPlaceAt(x, y)
  } else {
    placing.value = null
  }
}

async function onSvgClick(e) {
  if (paletteDragging.value) return
  const { x, y } = screenToMap(e, e.currentTarget)
  if (placing.value) {
    await commitPlaceAt(x, y)
    return
  }
  // Клик по пустому месту — снять выделение (панель навигации скроется)
  if (!moveMode.value && !plotDrag.value) {
    selectedId.value = null
  }
}

function onSvgMove(e) {
  if (!placing.value || paletteDragging.value) return
  updateGhostFromClient(e.clientX, e.clientY)
}

/** Плюсик: копия дороги встык, дальше тащим мышкой. */
function extendRoadSelected() {
  if (!selected.value || selected.value.kind !== 'road') return
  const tpl =
    roadTemplates.value.find((t) => t.key === (selected.value.template_key || 'road_tile')) ||
    roadTemplates.value[0] || {
      key: 'road_tile',
      kind: 'road',
      title: selected.value.title || 'Дорога 16×16',
      width_m: selected.value.width_m,
      depth_m: selected.value.depth_m,
      floor_plan: null,
    }
  const src = selected.value
  const w = Number(src.width_m)
  const d = Number(src.depth_m)
  placing.value = { ...tpl, key: tpl.key || 'road_tile' }
  moveMode.value = false
  const snapped = snapRoadTopLeft(src.x_m + w, src.y_m, w, d, src.id)
  ghost.value = {
    x_m: snapped.x_m,
    y_m: snapped.y_m,
    rotation_deg: src.rotation_deg || 0,
    width_m: w,
    depth_m: d,
    kind: 'road',
    title: tpl.title || 'Дорога 16×16',
    floor_plan: null,
    template_key: tpl.key || src.template_key || 'road_tile',
    road_mode: tpl.road_mode || src.road_mode || null,
    is_pinned: 0,
  }
}

function selectPlot(p) {
  if (placing.value) return
  if (moveMode.value && selectedId.value && Number(p.id) !== Number(selectedId.value)) {
    // в режиме переноса не сбрасываем, пока не закрепили
    return
  }
  selectedId.value = p.id
  if (!moveMode.value) {
    /* обычный выбор */
  }
}

/** Передвинуть: открепить и таскать мышкой; кнопка станет «закрепить». */
async function startMoveSelected() {
  if (!selected.value || busy.value) return
  placing.value = null
  if (selected.value.is_pinned) {
    busy.value = true
    try {
      const res = await api.layoutPlotUpdate(
        props.worldId,
        props.settlementId,
        selected.value.id,
        { is_pinned: 0 }
      )
      applyLayout(res.layout)
    } catch (e) {
      emit('error', e.message)
      return
    } finally {
      busy.value = false
    }
  }
  moveMode.value = true
}

function onPlotPointerDown(e, p) {
  if (placing.value) return
  if (e.button !== 0) return
  // Без «передвинуть» только выбор — иначе всё случайно разъезжается
  if (!moveMode.value) {
    selectedId.value = p.id
    return
  }
  if (Number(p.id) !== Number(selectedId.value)) return
  if (p.is_pinned) return
  e.stopPropagation()
  e.preventDefault()
  const svg = mapSvgEl.value
  if (!svg) return
  const { x, y } = screenToMap(e, svg)
  plotDrag.value = {
    id: p.id,
    grabDx: x - Number(p.x_m),
    grabDy: y - Number(p.y_m),
  }
  viewportEl.value?.setPointerCapture?.(e.pointerId)
}

/**
 * Подтянуть выделенный объект к соседям: только сантиметровые зазоры
 * (до ~20 см). Метровые/полуметровые щели не трогаем.
 */
function computeAlignNudge(plot) {
  const THRESH = 0.2
  const me = plotBox(plot)
  let nx = me.x
  let ny = me.y
  let bestDx = Infinity
  let bestDy = Infinity

  const considerX = (candidateX) => {
    const dx = Math.abs(candidateX - me.x)
    if (dx > 0.001 && dx <= THRESH && dx < bestDx) {
      bestDx = dx
      nx = candidateX
    }
  }
  const considerY = (candidateY) => {
    const dy = Math.abs(candidateY - me.y)
    if (dy > 0.001 && dy <= THRESH && dy < bestDy) {
      bestDy = dy
      ny = candidateY
    }
  }

  for (const other of mapItems.value) {
    if (Number(other.id) === Number(plot.id)) continue
    const o = plotBox(other)
    // Встык по X (лево/право к краю соседа)
    considerX(o.x + o.w)
    considerX(o.x - me.w)
    // Совпадение левых / правых рёбер
    considerX(o.x)
    considerX(o.x + o.w - me.w)
    // Встык по Y
    considerY(o.y + o.d)
    considerY(o.y - me.d)
    considerY(o.y)
    considerY(o.y + o.d - me.d)
  }

  if (bestDx === Infinity && bestDy === Infinity) return null
  const cl = clampTopLeft(nx, ny, me.w, me.d)
  if (Math.abs(cl.x_m - me.x) < 0.001 && Math.abs(cl.y_m - me.y) < 0.001) return null
  return cl
}

async function alignSelected() {
  if (!selected.value || busy.value) return
  if (selected.value.is_pinned && !moveMode.value) {
    emit('error', 'Сначала «передвинуть», потом выровнять')
    return
  }
  const nudge = computeAlignNudge(selected.value)
  if (!nudge) {
    emit('error', 'Рядом нет сантиметрового зазора для стыка')
    return
  }
  const nextBox = {
    x: nudge.x_m,
    y: nudge.y_m,
    w: Number(selected.value.width_m) || 0,
    d: Number(selected.value.depth_m) || 0,
  }
  if (selected.value.kind !== 'road' && overlapsAnyRoad(nextBox, selected.value.id)) {
    emit('error', 'После выравнивания здание ляжет на дорогу')
    return
  }
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { x_m: nudge.x_m, y_m: nudge.y_m }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

async function rotateSelected() {
  if (!selected.value || selected.value.is_pinned) return
  const next = ((Number(selected.value.rotation_deg) || 0) + 90) % 360
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { rotation_deg: next }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

/** Вывернуть по глубине: дверь N↔S (во двор с другой стороны). */
async function flipYSelected() {
  if (!selected.value || selected.value.is_pinned) return
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { flip_y: selected.value.flip_y ? 0 : 1 }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

/** Зеркало по фасаду: холл/выход L↔R. */
async function flipXSelected() {
  if (!selected.value || selected.value.is_pinned) return
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { flip_x: selected.value.flip_x ? 0 : 1 }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

async function pinSelected() {
  if (!selected.value) return
  const p = selected.value
  if (p.kind !== 'road' && overlapsAnyRoad(plotBox(p), p.id)) {
    emit('error', 'Нельзя закрепить здание на дороге')
    return
  }
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      p.id,
      {
        x_m: Number(Number(p.x_m).toFixed(2)),
        y_m: Number(Number(p.y_m).toFixed(2)),
        is_pinned: 1,
      }
    )
    applyLayout(res.layout)
    moveMode.value = false
    plotDrag.value = null
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

async function unpinSelected() {
  if (!selected.value) return
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { is_pinned: 0 }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

async function deleteSelected() {
  if (!selected.value) return
  if (!confirm(`Убрать «${selected.value.title}» с карты?`)) return
  busy.value = true
  try {
    const res = await api.layoutPlotDelete(
      props.worldId,
      props.settlementId,
      selected.value.id
    )
    applyLayout(res.layout)
    selectedId.value = null
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

/** Строится ↔ готово (снимает/ставит значок стройки). */
async function setBuildStatusSelected(status) {
  if (!selected.value || selected.value.kind === 'road' || busy.value) return
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { build_status: status }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

const firmBindOptions = FIRM_CODES

async function bindFirmSelected(code) {
  if (!selected.value || selected.value.kind === 'road' || busy.value) return
  const next = code || ''
  if ((selected.value.company_code || '') === next) return
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      { company_code: next }
    )
    applyLayout(res.layout)
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

function startSizeEdit() {
  if (!canResizeSelected.value || !selected.value) return
  draftSize.value = {
    width_m: Number(selected.value.width_m) || 6,
    depth_m: Number(selected.value.depth_m) || 5,
  }
  sizeEditOpen.value = true
}

function cancelSizeEdit() {
  sizeEditOpen.value = false
}

async function applySizeEdit() {
  if (!canResizeSelected.value || !selected.value || busy.value) return
  const w = Number(draftSize.value.width_m)
  const d = Number(draftSize.value.depth_m)
  if (!(w >= 1) || !(d >= 1)) {
    emit('error', 'Ширина и глубина — минимум 1 м')
    return
  }
  const nextBox = {
    x: Number(selected.value.x_m) || 0,
    y: Number(selected.value.y_m) || 0,
    w,
    d,
  }
  if (overlapsAnyRoad(nextBox, selected.value.id)) {
    emit('error', 'После изменения размера ляжет на дорогу')
    return
  }
  const cl = clampTopLeft(nextBox.x, nextBox.y, w, d)
  busy.value = true
  try {
    const res = await api.layoutPlotUpdate(
      props.worldId,
      props.settlementId,
      selected.value.id,
      {
        width_m: Number(w.toFixed(2)),
        depth_m: Number(d.toFixed(2)),
        x_m: cl.x_m,
        y_m: cl.y_m,
      }
    )
    applyLayout(res.layout)
    sizeEditOpen.value = false
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

function onPointerDown(e) {
  if (e.button !== 0) return
  if (placing.value || plotDrag.value) return
  // В режиме переноса карта не панится кликом по зданию; Alt — панорама
  if (moveMode.value && !e.altKey) return
  if (!e.altKey && e.target?.closest?.('.plot-g')) return
  dragging.value = true
  dragOrigin.value = {
    x: e.clientX,
    y: e.clientY,
    panX: panX.value,
    panY: panY.value,
  }
  e.currentTarget.setPointerCapture?.(e.pointerId)
}

function onPointerMove(e) {
  if (plotDrag.value && mapSvgEl.value) {
    const { x, y } = screenToMap(e, mapSvgEl.value)
    const idx = localPlots.value.findIndex(
      (p) => Number(p.id) === Number(plotDrag.value.id)
    )
    if (idx >= 0) {
      let nx = Number((x - plotDrag.value.grabDx).toFixed(2))
      let ny = Number((y - plotDrag.value.grabDy).toFixed(2))
      const cur = localPlots.value[idx]
      if (cur.kind === 'road') {
        const sn = snapRoadTopLeft(nx, ny, cur.width_m, cur.depth_m, cur.id)
        nx = sn.x_m
        ny = sn.y_m
      }
      const cl = clampTopLeft(nx, ny, cur.width_m, cur.depth_m)
      localPlots.value[idx] = { ...cur, x_m: cl.x_m, y_m: cl.y_m }
    }
    return
  }
  if (!dragging.value) return
  panX.value = dragOrigin.value.panX + (e.clientX - dragOrigin.value.x)
  panY.value = dragOrigin.value.panY + (e.clientY - dragOrigin.value.y)
}

async function onPointerUp(e) {
  if (plotDrag.value) {
    const id = plotDrag.value.id
    const p = localPlots.value.find((row) => Number(row.id) === Number(id))
    plotDrag.value = null
    try {
      e.currentTarget.releasePointerCapture?.(e.pointerId)
    } catch {
      /* already released */
    }
    if (p && !busy.value) {
      if (p.kind !== 'road' && overlapsAnyRoad(plotBox(p), p.id)) {
        emit('error', 'Нельзя поставить здание на дорогу')
        syncFromLayout()
        return
      }
      busy.value = true
      try {
        const res = await api.layoutPlotUpdate(
          props.worldId,
          props.settlementId,
          id,
          {
            x_m: Number(Number(p.x_m).toFixed(2)),
            y_m: Number(Number(p.y_m).toFixed(2)),
          }
        )
        applyLayout(res.layout)
      } catch (err) {
        emit('error', err.message)
      } finally {
        busy.value = false
      }
    }
    return
  }
  dragging.value = false
  try {
    e.currentTarget.releasePointerCapture?.(e.pointerId)
  } catch {
    /* ok */
  }
}

const dormSummary = computed(() =>
  (props.layout?.dorms || []).map((d) => ({
    title: d.title,
    occ: Number(d.occupied) || 0,
    cap: Number(d.capacity) || 0,
    size: `${d.width_m}×${d.depth_m} м`,
  }))
)
</script>

<template>
  <div class="layout-wrap">
    <button type="button" class="map-preview" title="Открыть редактор карты" @click="openModal">
      <svg class="preview-svg" :viewBox="viewBox">
        <rect
          class="ground"
          :x="mapMinX"
          :y="mapMinY"
          :width="mapW"
          :height="mapH"
        />
        <line
          class="origin-axis"
          :x1="mapMinX"
          :y1="originY"
          :x2="mapMaxX"
          :y2="originY"
        />
        <line
          class="origin-axis"
          :x1="originX"
          :y1="mapMinY"
          :x2="originX"
          :y2="mapMaxY"
        />
        <circle class="origin-dot" :cx="originX" :cy="originY" r="1.2" />
        <SettlementPlotGfx v-for="p in mapItems" :key="p.id" :plot="p" />
      </svg>
      <span class="preview-hint">редактор карты</span>
    </button>

    <p class="scale">
      Холст <strong>{{ mapW }}×{{ mapH }} м</strong>
      · нуль ({{ originX }}, {{ originY }})
      · резерв улицы <strong>{{ streetReserve }} м</strong>
      · зданий: {{ buildings.length }}
      · дорог: {{ roads.length }}
    </p>
    <ul v-if="dormSummary.length" class="dorm-list">
      <li v-for="(d, i) in dormSummary" :key="i">
        {{ d.title }}: <strong>{{ d.occ }}</strong> / {{ d.cap }}
        <span class="size">{{ d.size }}</span>
      </li>
    </ul>

    <Teleport to="body">
      <div
        v-if="open"
        class="map-backdrop"
        role="dialog"
        aria-modal="true"
        aria-label="Редактор застройки"
        @click.self="closeModal"
      >
        <div class="map-modal editor">
          <header class="map-modal-head">
            <div>
              <h2>Редактор застройки</h2>
              <p class="sub">
                Холст — плюсики по краям (шаг сетки ×1/×5/×10). Объект за край не тянет карту.
                Alt+лкм = сдвиг.
              </p>
            </div>
            <div class="zoom-bar">
              <label class="grid-tog">
                <input v-model="showGrid" type="checkbox" />
                сетка
              </label>
              <select v-model.number="gridStep" class="grid-sel">
                <option :value="1">1 м</option>
                <option :value="5">5 м</option>
                <option :value="10">10 м</option>
              </select>
              <span class="expand-tog" title="Сколько метров добавить плюсиком">
                край
                <button
                  v-for="m in [1, 5, 10]"
                  :key="'em' + m"
                  type="button"
                  class="zbtn"
                  :class="{ on: expandMult === m }"
                  @click="expandMult = m"
                >
                  ×{{ m }}
                </button>
                <span class="expand-hint">{{ expandLabel }}</span>
              </span>
              <button type="button" class="zbtn" :disabled="zoom <= MIN_ZOOM" @click="zoomBy(-ZOOM_STEP)">
                −
              </button>
              <span class="zlabel">{{ zoomLabel }}</span>
              <button type="button" class="zbtn" :disabled="zoom >= MAX_ZOOM" @click="zoomBy(ZOOM_STEP)">
                +
              </button>
              <button type="button" class="zbtn ghost" @click="resetView">сброс</button>
              <button type="button" class="zbtn close" aria-label="Закрыть" @click="closeModal">×</button>
            </div>
          </header>

          <div class="workspace">
            <div class="map-column">
            <div
              ref="viewportEl"
              class="map-viewport"
              :class="{
                dragging,
                placing: !!placing,
                moving: moveMode,
                'plot-dragging': !!plotDrag,
                'palette-drag': paletteDragging,
              }"
              @wheel.prevent="onWheel"
              @pointerdown="onPointerDown"
              @pointermove="onPointerMove"
              @pointerup="onPointerUp"
              @pointercancel="onPointerUp"
            >
              <div class="map-edge-controls" @pointerdown.stop @click.stop>
                <button
                  type="button"
                  class="edge-plus n"
                  :disabled="busy"
                  :title="'Расширить вверх ' + expandLabel"
                  @click="expandCanvasEdge('n')"
                >
                  +
                </button>
                <button
                  type="button"
                  class="edge-plus s"
                  :disabled="busy"
                  :title="'Расширить вниз ' + expandLabel"
                  @click="expandCanvasEdge('s')"
                >
                  +
                </button>
                <button
                  type="button"
                  class="edge-plus w"
                  :disabled="busy"
                  :title="'Расширить влево ' + expandLabel"
                  @click="expandCanvasEdge('w')"
                >
                  +
                </button>
                <button
                  type="button"
                  class="edge-plus e"
                  :disabled="busy"
                  :title="'Расширить вправо ' + expandLabel"
                  @click="expandCanvasEdge('e')"
                >
                  +
                </button>
              </div>
              <div class="map-stage" :style="{ transform: mapTransform }">
                <svg
                  ref="mapSvgEl"
                  class="metric-map"
                  :viewBox="viewBox"
                  @click="onSvgClick"
                  @mousemove="onSvgMove"
                >
                  <rect
                    class="ground"
                    :x="mapMinX"
                    :y="mapMinY"
                    :width="mapW"
                    :height="mapH"
                  />
                  <g v-if="showGrid" class="grid">
                    <line
                      v-for="x in gridLines.xs"
                      :key="'gx' + x.abs"
                      :x1="x.abs"
                      :y1="mapMinY"
                      :x2="x.abs"
                      :y2="mapMaxY"
                      :class="{
                        major: x.isOrigin || Math.abs(x.rel) % (gridStep * 2) < 0.001,
                        origin: x.isOrigin,
                      }"
                    />
                    <line
                      v-for="y in gridLines.ys"
                      :key="'gy' + y.abs"
                      :x1="mapMinX"
                      :y1="y.abs"
                      :x2="mapMaxX"
                      :y2="y.abs"
                      :class="{
                        major: y.isOrigin || Math.abs(y.rel) % (gridStep * 2) < 0.001,
                        origin: y.isOrigin,
                      }"
                    />
                  </g>
                  <!-- Нулевой метр: крест (не прыгает при расширении) -->
                  <g class="origin-cross" pointer-events="none">
                    <line
                      class="origin-axis"
                      :x1="mapMinX"
                      :y1="originY"
                      :x2="mapMaxX"
                      :y2="originY"
                    />
                    <line
                      class="origin-axis"
                      :x1="originX"
                      :y1="mapMinY"
                      :x2="originX"
                      :y2="mapMaxY"
                    />
                    <circle class="origin-dot" :cx="originX" :cy="originY" r="0.45" />
                    <text
                      class="origin-label"
                      :x="originX + 0.7"
                      :y="originY - 0.55"
                      font-size="1.1"
                    >
                      0
                    </text>
                  </g>
                  <SettlementPlotGfx
                    v-for="p in mapItems"
                    :key="p.id"
                    :plot="p"
                    :selected="Number(p.id) === Number(selectedId)"
                    @pointerdown="onPlotPointerDown($event, p)"
                    @click.stop="selectPlot(p)"
                  />
                  <SettlementPlotGfx
                    v-if="placing"
                    :plot="{ ...ghost, id: 'ghost' }"
                    ghost
                  />
                </svg>
              </div>
            </div>

            <!-- Панель под картой: только при выделении, в одну строку -->
            <div v-if="selected" class="map-nav" aria-label="Навигация объекта">
              <span class="sel-title">{{ selected.title }}</span>
              <span class="nav-meta">
                {{ selected.width_m }}×{{ selected.depth_m }} · {{ selected.rotation_deg || 0 }}°
                <template v-if="selected.flip_y"> · выв.</template>
                <template v-if="selected.flip_x"> · зерк.</template>
                <template v-if="selected.build_status === 'building'"> · строится</template>
              </span>
              <label v-if="selected.kind !== 'road'" class="firm-bind">
                <span class="firm-bind-label">фирма</span>
                <FirmPick
                  :model-value="selected.company_code || ''"
                  :options="firmBindOptions"
                  placeholder="привязать фирму…"
                  @update:model-value="bindFirmSelected"
                />
              </label>
              <div v-if="sizeEditOpen && canResizeSelected" class="size-edit">
                <label>
                  ширина
                  <input v-model.number="draftSize.width_m" type="number" min="1" step="0.1" />
                </label>
                <label>
                  глубина
                  <input v-model.number="draftSize.depth_m" type="number" min="1" step="0.1" />
                </label>
                <button type="button" class="primary" :disabled="busy" @click="applySizeEdit">
                  ок
                </button>
                <button type="button" class="zbtn" :disabled="busy" @click="cancelSizeEdit">
                  отмена
                </button>
              </div>
              <div class="sel-actions row">
                <button
                  v-if="selected.kind === 'road'"
                  type="button"
                  class="primary"
                  :disabled="busy"
                  @click="extendRoadSelected"
                >
                  + продлить
                </button>
                <button
                  v-if="canResizeSelected && !sizeEditOpen"
                  type="button"
                  class="zbtn"
                  :disabled="busy"
                  title="Изменить ширину и глубину площадки"
                  @click="startSizeEdit"
                >
                  изменить
                </button>
                <button
                  v-if="selected.kind !== 'road' && selected.build_status === 'building'"
                  type="button"
                  class="primary"
                  :disabled="busy"
                  title="Снять статус стройки"
                  @click="setBuildStatusSelected('ready')"
                >
                  готово
                </button>
                <button
                  v-else-if="selected.kind !== 'road'"
                  type="button"
                  class="zbtn"
                  :disabled="busy"
                  title="Пометить как стройку"
                  @click="setBuildStatusSelected('building')"
                >
                  в стройку
                </button>
                <button
                  type="button"
                  class="zbtn"
                  :disabled="busy || (!!selected.is_pinned && !moveMode)"
                  @click="rotateSelected"
                >
                  поворот
                </button>
                <button
                  type="button"
                  class="zbtn"
                  :disabled="busy || (!!selected.is_pinned && !moveMode)"
                  :class="{ on: !!selected.flip_y }"
                  @click="flipYSelected"
                >
                  инверсия
                </button>
                <button
                  type="button"
                  class="zbtn"
                  :disabled="busy || (!!selected.is_pinned && !moveMode)"
                  :class="{ on: !!selected.flip_x }"
                  @click="flipXSelected"
                >
                  зеркало
                </button>
                <button
                  type="button"
                  class="zbtn"
                  :disabled="busy || (!!selected.is_pinned && !moveMode)"
                  title="Подтянуть к соседу, если зазор до ~20 см"
                  @click="alignSelected"
                >
                  выровнять
                </button>
                <button
                  v-if="!moveMode"
                  type="button"
                  class="zbtn"
                  :disabled="busy"
                  @click="startMoveSelected"
                >
                  передвинуть
                </button>
                <button
                  v-else
                  type="button"
                  class="primary"
                  :disabled="busy"
                  @click="pinSelected"
                >
                  закрепить
                </button>
                <button type="button" class="danger" :disabled="busy" @click="deleteSelected">
                  убрать
                </button>
              </div>
            </div>
            </div>

            <!-- Справа: только уже стоящие объекты -->
            <aside class="side-dock" aria-label="Объекты на карте">
              <p class="dock-label">На карте</p>
              <div class="dock-placed">
                <p v-if="!mapItems.length" class="hint tight">Пока пусто — добавь снизу</p>
                <button
                  v-for="p in mapItems"
                  :key="'list-' + p.id"
                  type="button"
                  class="tpl tpl-mini placed"
                  :class="{ on: Number(p.id) === Number(selectedId) }"
                  @click="selectPlaced(p)"
                >
                  <svg class="tpl-thumb" :viewBox="miniViewBox(p)" aria-hidden="true">
                    <SettlementPlotGfx :plot="miniFromPlot(p)" />
                  </svg>
                  <span class="tpl-meta">
                    <span class="tpl-title">
                      <img
                        v-if="firmLogoSrc(p.company_code)"
                        class="placed-firm-logo"
                        :src="firmLogoSrc(p.company_code)"
                        alt=""
                      />
                      {{ p.title || p.kind }}
                      <span v-if="p.build_status === 'building'" class="build-tag">стройка</span>
                    </span>
                    <span class="tpl-size">
                      {{ p.width_m }}×{{ p.depth_m }}
                      <template v-if="p.company_code"> · {{ p.company_code }}</template>
                    </span>
                  </span>
                </button>
              </div>
            </aside>
          </div>

          <!-- Внизу: фирма 2 + палитра 1 -->
          <div class="bottom-bar">
            <div class="bottom-firm">
              <p class="dock-label">Своя фирма</p>
              <div class="firm-row">
                <label class="firm-name">
                  Имя
                  <input v-model="draftFirm.title" type="text" placeholder="ЛесПром" />
                </label>
                <label class="firm-dim">
                  ширина
                  <input v-model.number="draftFirm.width_m" type="number" min="1" step="0.1" />
                </label>
                <label class="firm-dim">
                  глубина
                  <input v-model.number="draftFirm.depth_m" type="number" min="1" step="0.1" />
                </label>
                <button type="button" class="primary firm-go" :disabled="busy" @click="createCustomFirm">
                  + на карту
                </button>
              </div>
            </div>

            <div class="bottom-catalog">
              <p class="dock-label">Новые — тяни на карту</p>
              <div class="bottom-thumbs">
                <button
                  v-for="t in buildingTemplates"
                  :key="t.key"
                  type="button"
                  class="tpl tpl-mini"
                  :class="{ on: placing?.key === t.key }"
                  :disabled="busy"
                  title="Зажми и тащи на карту"
                  @pointerdown="onPaletteGrab($event, t)"
                >
                  <svg class="tpl-thumb" :viewBox="miniViewBox(t)" aria-hidden="true">
                    <SettlementPlotGfx :plot="miniFromTemplate(t)" />
                  </svg>
                  <span class="tpl-meta">
                    <span class="tpl-title">{{ t.title }}</span>
                    <span class="tpl-size">{{ t.width_m }}×{{ t.depth_m }}</span>
                  </span>
                </button>
                <button
                  v-for="t in roadTemplates"
                  :key="t.key"
                  type="button"
                  class="tpl tpl-mini"
                  :class="{ on: placing?.key === t.key }"
                  :disabled="busy"
                  title="Зажми и тащи на карту"
                  @pointerdown="onPaletteGrab($event, t)"
                >
                  <svg class="tpl-thumb" :viewBox="miniViewBox(t)" aria-hidden="true">
                    <SettlementPlotGfx :plot="miniFromTemplate(t)" />
                  </svg>
                  <span class="tpl-meta">
                    <span class="tpl-title">{{ t.title }}</span>
                    <span class="tpl-size">{{ t.width_m }}×{{ t.depth_m }}</span>
                  </span>
                </button>
              </div>
            </div>
          </div>

          <footer class="map-modal-foot">
            Холст {{ mapW }}×{{ mapH }} м · нуль ({{ originX }}, {{ originY }}) · край
            {{ expandLabel }} · на дорогу дома не ставятся
          </footer>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.layout-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.map-preview {
  position: relative;
  display: block;
  width: 100%;
  max-width: 420px;
  margin: 0;
  padding: 0.35rem;
  border: 1px solid #b7c4a4;
  border-radius: 8px;
  background: #e9eee2;
  cursor: zoom-in;
  text-align: left;
  font: inherit;
}
.preview-svg {
  width: 100%;
  height: auto;
  display: block;
  min-height: 120px;
}
.ground {
  fill: #f7f5ec;
}
.preview-hint {
  position: absolute;
  right: 0.45rem;
  bottom: 0.35rem;
  font-size: 0.7rem;
  color: #2a3d32;
  background: rgba(255, 252, 245, 0.9);
  border: 1px solid #c5d0b5;
  padding: 0.15rem 0.4rem;
  border-radius: 3px;
}
.scale,
.legend,
.hint {
  margin: 0;
  font-size: 0.8rem;
  color: #5a6b4e;
}
.dorm-list {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}
.dorm-list .size {
  color: #6a7b72;
  margin-left: 0.35rem;
  font-size: 0.78rem;
}

.map-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  background: rgba(12, 20, 16, 0.72);
  display: flex;
  align-items: stretch;
  justify-content: center;
  padding: 0.75rem;
}
.map-modal.editor {
  width: min(1440px, calc(100vw - 1.5rem));
  height: min(920px, calc(100vh - 1.5rem));
  max-height: 100%;
  display: flex;
  flex-direction: column;
  background: #f7f4ea;
  border: 1px solid #b7c4a4;
  border-radius: 8px;
  overflow: hidden;
}
.map-modal-head {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.7rem 0.9rem;
  border-bottom: 1px solid #c5d0b5;
  background: #eef2e4;
  flex-shrink: 0;
}
.map-modal-head h2 {
  margin: 0;
  font-size: 1.05rem;
  color: #1f3d28;
}
.sub {
  margin: 0.2rem 0 0;
  font-size: 0.78rem;
  color: #5a6b4e;
}
.zoom-bar {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex-wrap: wrap;
}
.grid-tog {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.8rem;
  color: #2a3d32;
}
.grid-sel {
  font: inherit;
  font-size: 0.8rem;
  padding: 0.2rem 0.35rem;
  border: 1px solid #9aab88;
  border-radius: 4px;
  background: #fff;
}
.zbtn {
  font: inherit;
  min-width: 2rem;
  height: 2rem;
  border: 1px solid #9aab88;
  background: #f3f0e6;
  color: #1f3d28;
  border-radius: 4px;
  cursor: pointer;
}
.zbtn.on {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
}
.zbtn.ghost {
  min-width: auto;
  padding: 0 0.55rem;
  font-size: 0.8rem;
}
.zbtn.close {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
  font-size: 1.2rem;
}
.zlabel {
  min-width: 3.2rem;
  text-align: center;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
}
.workspace {
  /* Всегда карта слева + боковик справа (не зависит от ширины окна) */
  display: flex;
  flex-direction: row;
  align-items: stretch;
  min-height: 0;
  flex: 1;
}
.map-column {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
}
.map-viewport {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
  background: #e9eee2;
  cursor: grab;
  position: relative;
  touch-action: none;
}
.map-edge-controls {
  position: absolute;
  inset: 0;
  z-index: 5;
  pointer-events: none;
}
.edge-plus {
  pointer-events: auto;
  position: absolute;
  width: 1.7rem;
  height: 1.7rem;
  border: 1px solid #2f5d3a;
  border-radius: 4px;
  background: rgba(47, 93, 58, 0.92);
  color: #fff;
  font: inherit;
  font-size: 1.15rem;
  line-height: 1;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.edge-plus:disabled {
  opacity: 0.45;
  cursor: wait;
}
.edge-plus.n {
  top: 0.4rem;
  left: 50%;
  transform: translateX(-50%);
}
.edge-plus.s {
  bottom: 0.4rem;
  left: 50%;
  transform: translateX(-50%);
}
.edge-plus.w {
  left: 0.4rem;
  top: 50%;
  transform: translateY(-50%);
}
.edge-plus.e {
  right: 0.4rem;
  top: 50%;
  transform: translateY(-50%);
}
.expand-tog {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  font-size: 0.78rem;
  color: #2a3d32;
}
.expand-tog .zbtn {
  min-width: 1.85rem;
  height: 1.7rem;
  font-size: 0.72rem;
  padding: 0 0.25rem;
}
.expand-hint {
  font-size: 0.72rem;
  color: #5a6b4e;
  margin-left: 0.15rem;
  font-variant-numeric: tabular-nums;
}
.map-nav {
  flex-shrink: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.65rem;
  padding: 0.35rem 0.55rem;
  border-top: 1px solid #c5d0b5;
  background: #e4ecdf;
}
.map-nav .sel-title {
  margin: 0;
  font-size: 0.8rem;
}
.map-nav .nav-meta {
  font-size: 0.72rem;
  color: #5a6b4e;
}
.firm-bind {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 11rem;
  max-width: 16rem;
  flex: 1 1 12rem;
}
.firm-bind-label {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #5a6b4e;
  flex-shrink: 0;
}
.firm-bind :deep(.firm-pick-btn) {
  min-height: 2rem;
  padding: 0.15rem 0.4rem;
  background: #fffcf5;
  border-color: #9aab88;
}
.firm-bind :deep(.firm-pick-logo) {
  width: 22px;
  height: 22px;
}
.firm-bind :deep(.firm-pick-menu) {
  z-index: 30;
  max-height: 240px;
}
.size-edit {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.4rem 0.55rem;
  padding: 0.15rem 0.35rem;
  background: #e8e4d8;
  border: 1px solid #c5d0b5;
  border-radius: 4px;
}
.size-edit label {
  font-size: 0.72rem;
  gap: 0.1rem;
}
.size-edit input {
  width: 4.5rem;
  padding: 0.25rem 0.35rem;
  font-size: 0.85rem;
}
.map-nav .sel-actions {
  margin-top: 0;
  margin-left: auto;
}
.placed-firm-logo {
  width: 16px;
  height: 16px;
  object-fit: contain;
  vertical-align: -3px;
  margin-right: 0.2rem;
  border-radius: 2px;
}
.map-viewport.dragging {
  cursor: grabbing;
}
.map-viewport.placing {
  cursor: crosshair;
}
.map-viewport.moving {
  cursor: grab;
}
.map-viewport.plot-dragging,
.map-viewport.palette-drag {
  cursor: grabbing;
}
.map-stage {
  width: min(100%, 1040px);
  margin: 0.6rem auto;
  padding: 0.4rem;
  transform-origin: center center;
  will-change: transform;
}
.metric-map {
  width: 100%;
  height: auto;
  display: block;
  background: #f7f5ec;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
}
.grid line {
  stroke: #d6dccb;
  stroke-width: 0.04;
}
.grid line.major {
  stroke: #b3bfa4;
  stroke-width: 0.07;
}
.grid line.origin {
  stroke: transparent;
}
.origin-axis {
  stroke: #8b4518;
  stroke-width: 0.18;
  stroke-dasharray: 0.6 0.35;
  opacity: 0.85;
}
.origin-dot {
  fill: #8b4518;
  stroke: #fffcf5;
  stroke-width: 0.12;
}
.origin-label {
  fill: #6b3a1f;
  font-family: system-ui, sans-serif;
  font-weight: 700;
  pointer-events: none;
}
.side-dock {
  flex: 0 0 188px;
  width: 188px;
  border-left: 2px solid #9aab88;
  background: #efebe0;
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
  z-index: 2;
  padding: 0.45rem;
}
.dock-label {
  margin: 0 0 0.3rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #5a6b4e;
}
.dock-placed {
  flex: 1;
  min-height: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.tpl.placed {
  cursor: pointer;
  touch-action: manipulation;
}
.bottom-bar {
  flex-shrink: 0;
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 0;
  border-top: 2px solid #9aab88;
  background: #efebe0;
  max-height: 220px;
  min-height: 0;
}
.bottom-firm {
  padding: 0.5rem 0.75rem;
  border-right: 1px solid #c5d0b5;
  background: #e8e4d8;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  overflow: hidden;
  min-width: 0;
  min-height: 0;
}
.firm-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.55rem 0.75rem;
  min-width: 0;
}
.firm-name {
  flex: 1 1 12rem;
  min-width: 8rem;
  max-width: 28rem;
}
.firm-dim {
  flex: 0 0 5.5rem;
  width: 5.5rem;
}
.firm-dim input {
  width: 100%;
  box-sizing: border-box;
}
.firm-name input {
  width: 100%;
  box-sizing: border-box;
}
.firm-go {
  flex: 0 0 auto;
  white-space: nowrap;
  align-self: flex-end;
}
.bottom-catalog {
  padding: 0.45rem 0.55rem;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.bottom-thumbs {
  display: flex;
  flex-direction: column;
  flex-wrap: nowrap;
  gap: 0.35rem;
  overflow-x: hidden;
  overflow-y: auto;
  min-height: 0;
  flex: 1;
}
.bottom-thumbs .tpl-mini {
  flex: 0 0 auto;
  width: 100%;
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
}
.bottom-thumbs .tpl-thumb {
  width: 72px;
  height: 48px;
  flex-shrink: 0;
}
.bottom-thumbs .tpl-meta {
  flex: 1;
  min-width: 0;
}
.map-modal-foot {
  flex-shrink: 0;
  padding: 0.35rem 0.75rem;
  border-top: 1px solid #c5d0b5;
  background: #eef2e4;
  font-size: 0.72rem;
  color: #5a6b4e;
}
.hint.tight {
  font-size: 0.7rem;
  line-height: 1.25;
  margin: 0;
}
.tpl {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.2rem;
  text-align: left;
  font: inherit;
  padding: 0.3rem;
  border: 1px solid #9aab88;
  border-radius: 4px;
  background: #fffcf5;
  cursor: grab;
  user-select: none;
  touch-action: none;
}
.tpl:active {
  cursor: grabbing;
}
.tpl-mini {
  gap: 0.2rem;
}
.tpl.on {
  border-color: #2f5d3a;
  background: #e4f0e2;
}
.tpl-thumb {
  width: 100%;
  height: 64px;
  display: block;
  background: #f7f5ec;
  border-radius: 3px;
  border: 1px solid #e0e6d6;
  pointer-events: none;
}
.tpl-meta {
  display: flex;
  flex-direction: column;
  gap: 0.02rem;
  padding: 0 0.05rem;
}
.tpl-title {
  font-weight: 600;
  font-size: 0.72rem;
  color: #1f3d28;
  line-height: 1.15;
}
.tpl-size {
  font-size: 0.64rem;
  color: #6a7b72;
}
.build-tag {
  display: inline-block;
  margin-left: 0.25rem;
  padding: 0.05rem 0.28rem;
  font-size: 0.58rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #1f3d28;
  background: #f0c040;
  border-radius: 3px;
  vertical-align: middle;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.78rem;
  color: #4a5740;
}
input {
  font: inherit;
  padding: 0.3rem 0.4rem;
  border: 1px solid #9aab88;
  border-radius: 3px;
}
.row2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.4rem;
}
.primary {
  font: inherit;
  border: none;
  background: #2f5d3a;
  color: #fff;
  padding: 0.45rem 0.7rem;
  border-radius: 4px;
  cursor: pointer;
}
.danger {
  font: inherit;
  border: none;
  background: #8b2e2e;
  color: #fff;
  padding: 0.35rem 0.55rem;
  border-radius: 4px;
  cursor: pointer;
}
.sel-title {
  margin: 0;
  font-weight: 600;
  font-size: 0.82rem;
  color: #1f3d28;
  line-height: 1.2;
}
.sel-actions {
  display: flex;
  flex-direction: column;
  gap: 0.28rem;
  margin-top: 0.35rem;
}
.sel-actions.row {
  flex-direction: row;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-top: 0.25rem;
}
.sel-actions .zbtn,
.sel-actions .primary,
.sel-actions .danger {
  width: 100%;
  min-width: 0;
  height: auto;
  padding: 0.35rem 0.4rem;
  font-size: 0.78rem;
}
.sel-actions.row .zbtn,
.sel-actions.row .primary,
.sel-actions.row .danger {
  width: auto;
  flex: 0 0 auto;
  padding: 0.3rem 0.55rem;
}

</style>
