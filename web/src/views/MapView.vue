<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'
import {
  MAP_SMALL,
  START_COLORS,
  TERRAIN,
  buildSmallProvinces,
  terrainFill,
  politicalFill,
  projectFlat,
  projectGlobe,
} from '../data/mapPuzzle'

const router = useRouter()
const createBusy = ref(false)
const createError = ref('')
const CREATE_DRAFT_KEY = 'civaris.map-puzzle.create-draft.v1'
const view = ref('flat')
const layer = ref('geo')
const selectedId = ref(null)
const hoverId = ref(null)
const lonOffset = ref(0)
const dragging = ref(false)
const dragMoved = ref(false)
const seed = ref(42)
const base = ref(buildSmallProvinces(seed.value))

function reshuffle() {
  seed.value = Math.floor(Math.random() * 100000)
  selectedId.value = null
  hoverId.value = null
  base.value = buildSmallProvinces(seed.value)
}

const flatW = 960
const flatH = 520
const globeSize = 540

let dragStartX = 0
let dragStartLon = 0

const projected = computed(() => {
  if (view.value === 'globe') return projectGlobe(base.value.provinces, globeSize, lonOffset.value)
  return projectFlat(base.value.provinces, flatW, flatH)
})

const visibleProvinces = computed(() => projected.value.filter((p) => p.visible))

const patches = computed(() => {
  const list = []
  for (const p of visibleProvinces.value) {
    for (const m of p.patches) {
      if (m.visible !== false) list.push({ ...m, depth: m.depth ?? p.depth })
    }
  }
  if (view.value === 'globe') list.sort((a, b) => a.depth - b.depth)
  return list
})

const starts = computed(() =>
  base.value.provinces.filter((p) => p.startIndex).sort((a, b) => a.startIndex - b.startIndex)
)

const selected = computed(() => projected.value.find((p) => p.id === selectedId.value) || null)
const hover = computed(() => projected.value.find((p) => p.id === hoverId.value) || null)

const viewBox = computed(() =>
  view.value === 'globe' ? `0 0 ${globeSize} ${globeSize}` : `0 0 ${flatW} ${flatH}`
)

const LEGEND_ORDER = [
  { key: 'sea', label: 'вода (пруд / озеро / море)' },
  { key: 'river', label: 'река' },
  { key: 'beach', label: 'пляж' },
  { key: 'forest', label: 'зелень (сквер / парк / лес)' },
  { key: 'mountain', label: 'горы / гряды' },
  { key: 'plain', label: 'равнины / поля' },
]

const worldStats = computed(() => {
  const c = base.value.counts
  const n = Object.values(c).reduce((a, b) => a + b, 0) || 1
  const bundle = {
    sea: (c.sea || 0) + (c.lake || 0) + (c.pond || 0),
    river: c.river || 0,
    beach: c.beach || 0,
    forest: (c.forest || 0) + (c.park || 0) + (c.square || 0),
    mountain: (c.mountain || 0) + (c.ridge || 0),
    plain: c.plain || 0,
  }
  return LEGEND_ORDER.map(({ key, label }) => ({
    key,
    ru: label,
    fill: TERRAIN[key].fill,
    n: bundle[key],
    pct: Math.round((bundle[key] / n) * 100),
  })).filter((t) => t.n > 0)
})

const provinceTileStats = computed(() => {
  const p = selected.value || hover.value
  if (!p) return null
  const c = p.tiles.counts
  const total = MAP_SMALL.tileSize * MAP_SMALL.tileSize
  const bundle = {
    sea: (c.sea || 0) + (c.lake || 0) + (c.pond || 0),
    river: c.river || 0,
    beach: c.beach || 0,
    forest: (c.forest || 0) + (c.park || 0) + (c.square || 0),
    mountain: (c.mountain || 0) + (c.ridge || 0),
    plain: c.plain || 0,
  }
  return LEGEND_ORDER.map(({ key, label }) => ({
    key,
    ru: label,
    fill: TERRAIN[key].fill,
    n: bundle[key],
    pct: Math.round((bundle[key] / total) * 100),
  })).filter((t) => t.n > 0)
})

const featureSummary = computed(() => {
  const m = base.value.meta || {}
  const uses = m.recipeUses || {}
  const top = Object.entries(uses)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 6)
  return {
    mode: m.mode,
    seed: m.seed,
    recipeCount: m.recipeCount,
    habitable: m.habitable,
    topRecipes: top,
  }
})

function opacityFor(depth) {
  if (view.value !== 'globe') return 1
  const t = Math.max(0, Math.min(1, (depth + 0.1) / 1.1))
  return 0.55 + t * 0.45
}

function borderStroke(p) {
  if (p.id === selectedId.value) return '#1a2e20'
  if (p.id === hoverId.value) return '#2a4a34'
  if (layer.value === 'political') return 'rgba(30, 40, 32, 0.55)'
  return 'rgba(35, 48, 38, 0.18)'
}

function borderWidth(p) {
  if (p.id === selectedId.value) return 1.6
  if (p.id === hoverId.value) return 1.1
  if (layer.value === 'political') return 0.9
  return 0.4
}

function onPick(p) {
  if (dragMoved.value) return
  selectedId.value = selectedId.value === p.id ? null : p.id
}

function onPointerDown(e) {
  if (view.value !== 'globe') return
  dragging.value = true
  dragMoved.value = false
  dragStartX = e.clientX
  dragStartLon = lonOffset.value
  e.currentTarget.setPointerCapture?.(e.pointerId)
}

function onPointerMove(e) {
  if (!dragging.value || view.value !== 'globe') return
  const dx = e.clientX - dragStartX
  if (Math.abs(dx) > 3) dragMoved.value = true
  lonOffset.value = dragStartLon + (dx / 180) * Math.PI
}

function onPointerUp() {
  dragging.value = false
}

onUnmounted(() => {
  dragging.value = false
})
</script>

<template>
  <section class="panel">
    <div class="head">
      <div>
        <router-link class="back" to="/">← на главную</router-link>
        <h1>Карта — каркас</h1>
        <p class="lead">
          {{ MAP_SMALL.label }}: {{ MAP_SMALL.provinceCount }} провинций · пазлы {{ MAP_SMALL.tileSize }}×{{ MAP_SMALL.tileSize }} (сектора {{ MAP_SMALL.sectorSize }}×{{ MAP_SMALL.sectorSize }})
        </p>
      </div>
    </div>

    <p v-if="createError" class="err">{{ createError }}</p>

    <div class="toolbar">
      <div class="group">
        <span class="lbl">Вид</span>
        <button type="button" :class="{ on: view === 'globe' }" @click="view = 'globe'">Шар</button>
        <button type="button" :class="{ on: view === 'flat' }" @click="view = 'flat'">Развёртка</button>
      </div>
      <div class="group">
        <span class="lbl">Слой</span>
        <button type="button" :class="{ on: layer === 'geo' }" @click="layer = 'geo'">Географическая</button>
        <button type="button" :class="{ on: layer === 'political' }" @click="layer = 'political'">
          Политическая
        </button>
      </div>
      <div class="group">
        <span class="lbl">Seed</span>
        <button type="button" @click="reshuffle">Другой мир</button>
        <span class="hint-inline">#{{ seed }}</span>
      </div>
      <div v-if="view === 'globe'" class="group">
        <span class="lbl">Шар</span>
        <span class="hint-inline">зацепи мышью и крути</span>
      </div>
    </div>

    <div class="stage">
      <div
        class="map-wrap"
        :class="[view, { dragging: dragging && view === 'globe' }]"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
      >
        <svg
          class="map-svg"
          :viewBox="viewBox"
          xmlns="http://www.w3.org/2000/svg"
          role="img"
          aria-label="Карта провинций Civaris"
        >
          <defs>
            <radialGradient id="ocean" cx="35%" cy="30%" r="70%">
              <stop offset="0%" stop-color="#9eb8c9" />
              <stop offset="55%" stop-color="#6a8fa3" />
              <stop offset="100%" stop-color="#3d5a68" />
            </radialGradient>
            <radialGradient id="shade" cx="30%" cy="28%" r="75%">
              <stop offset="40%" stop-color="rgba(0,0,0,0)" />
              <stop offset="100%" stop-color="rgba(15,25,20,0.45)" />
            </radialGradient>
          </defs>

          <circle
            v-if="view === 'globe'"
            :cx="globeSize / 2"
            :cy="globeSize / 2"
            :r="globeSize * 0.42"
            fill="url(#ocean)"
          />
          <rect v-else x="0" y="0" :width="flatW" :height="flatH" fill="#5f8796" rx="2" />

          <template v-if="layer === 'geo'">
            <polygon
              v-for="m in patches"
              :key="m.id"
              :points="m.pointsAttr"
              :fill="terrainFill(m.terrain)"
              stroke="none"
              :opacity="opacityFor(m.depth)"
              pointer-events="none"
            />
          </template>

          <!-- непригодные провинции приглушаем поверх -->
          <polygon
            v-for="p in visibleProvinces.filter((x) => !x.tiles.habitable)"
            :key="'dim' + p.id"
            :points="p.pointsAttr"
            fill="rgba(40,45,38,0.28)"
            stroke="none"
            pointer-events="none"
          />

          <polygon
            v-if="layer === 'political'"
            v-for="p in visibleProvinces"
            :key="'pol' + p.id"
            :points="p.pointsAttr"
            :fill="politicalFill(p)"
            stroke="none"
            :opacity="opacityFor(p.depth)"
            class="prov"
            @mouseenter="hoverId = p.id"
            @mouseleave="hoverId = null"
            @click="onPick(p)"
          />

          <polygon
            v-for="p in visibleProvinces"
            :key="'b' + p.id"
            :points="p.pointsAttr"
            fill="transparent"
            :stroke="borderStroke(p)"
            :stroke-width="borderWidth(p)"
            stroke-linejoin="miter"
            vector-effect="non-scaling-stroke"
            class="prov"
            @mouseenter="hoverId = p.id"
            @mouseleave="hoverId = null"
            @click="onPick(p)"
          />

          <g pointer-events="none">
            <template v-for="p in visibleProvinces" :key="'lab' + p.id">
              <text :x="p.cx" :y="p.cy + 4" text-anchor="middle" class="num">{{ p.id }}</text>
              <circle
                v-if="p.startIndex"
                :cx="p.cx"
                :cy="p.cy - 11"
                r="4.5"
                fill="#fff8e8"
                stroke="#1f3d28"
                stroke-width="1.2"
              />
            </template>
          </g>

          <circle
            v-if="view === 'globe'"
            :cx="globeSize / 2"
            :cy="globeSize / 2"
            :r="globeSize * 0.42"
            fill="url(#shade)"
            pointer-events="none"
          />
        </svg>
        <p class="note">
          Стыковка краёв Carcassonne-style · повороты 0–270° · каталог {{ featureSummary.recipeCount }} рецептов
        </p>
      </div>

      <aside class="side">
        <h2>Рельеф мира</h2>
        <ul class="legend">
          <li v-for="t in worldStats" :key="t.key">
            <span class="swatch" :style="{ background: t.fill }" />
            {{ t.ru }} — {{ t.pct }}%
          </li>
        </ul>

        <h2>Пазлы</h2>
        <ul class="legend">
          <li>режим: {{ featureSummary.mode }} · seed {{ seed }}</li>
          <li>рецептов в каталоге: {{ featureSummary.recipeCount }}</li>
          <li>пригодных провинций: {{ featureSummary.habitable }} / {{ MAP_SMALL.provinceCount }}</li>
          <li v-for="[id, n] in featureSummary.topRecipes" :key="id">{{ id }} ×{{ n }}</li>
        </ul>

        <h2 v-if="layer === 'political'">Старты</h2>
        <ul v-if="layer === 'political'" class="legend">
          <li v-for="s in starts" :key="s.id">
            <span class="swatch" :style="{ background: START_COLORS[s.startIndex - 1] }" />
            Старт {{ s.startIndex }} · #{{ s.id }}
          </li>
        </ul>

        <h2>Провинция</h2>
        <div v-if="selected || hover" class="card">
          <p>
            <strong>#{{ (selected || hover).id }}</strong>
            · ряд {{ (selected || hover).row + 1 }}, кол. {{ (selected || hover).col + 1 }}
          </p>
          <p v-if="(selected || hover).puzzle" class="muted">
            пазл {{ (selected || hover).puzzle.id }} · {{ (selected || hover).puzzle.label }} · поворот
            {{ (selected || hover).puzzle.rot * 90 }}°
          </p>
          <p :class="(selected || hover).tiles.habitable ? 'ok' : 'muted'">
            {{ (selected || hover).tiles.habitable ? 'Пригодна для жизни' : 'Малопригодна (затемнена)' }}
          </p>
          <ul v-if="provinceTileStats" class="mini">
            <li v-for="t in provinceTileStats" :key="t.key">
              <span class="swatch sm" :style="{ background: t.fill }" />
              {{ t.ru }}: {{ t.pct }}%
            </li>
          </ul>
          <p v-if="(selected || hover).startIndex" class="ok">
            Стартовый очаг {{ (selected || hover).startIndex }}
          </p>
        </div>
        <p v-else class="muted">Наведи или кликни провинцию</p>

        <p class="hint">
          Канон: docs/map-puzzle-rules.md · крупные леса/моря/массивы пока внутри одного пазла; склейка через края.
        </p>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.panel {
  background: rgba(255, 252, 245, 0.92);
  border: 1px solid #b7c4a4;
  border-radius: 4px;
  padding: 1rem 1.1rem 1.35rem;
}
.head {
  margin-bottom: 0.75rem;
}
.back {
  color: #2f5d3a;
  font-size: 0.9rem;
}
h1 {
  margin: 0.2rem 0;
  font-size: 1.35rem;
  color: #1f3d28;
}
.lead {
  margin: 0;
  color: #5a6b4e;
  font-size: 0.92rem;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 0.9rem;
}
.group {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}
.lbl {
  font-size: 0.78rem;
  color: #5a6b4e;
  margin-right: 0.25rem;
}
.hint-inline {
  font-size: 0.78rem;
  color: #5a6b4e;
}
.toolbar button {
  border: 1px solid #9aab88;
  background: #f3f0e6;
  padding: 0.35rem 0.7rem;
  border-radius: 3px;
  font: inherit;
  cursor: pointer;
  color: #1f3d28;
}
.toolbar button.on {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
}
.toolbar button.create-world {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
}
.err {
  color: #8b2e2e;
  background: #f7e4e4;
  padding: 0.5rem 0.75rem;
  border-radius: 3px;
  margin-bottom: 0.75rem;
}
.stage {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(210px, 290px);
  gap: 0.85rem;
  align-items: start;
}
@media (max-width: 860px) {
  .stage {
    grid-template-columns: 1fr;
  }
}
.map-wrap {
  background: #e8eedf;
  border: 1px solid #a7b894;
  border-radius: 3px;
  padding: 0.65rem;
}
.map-wrap.globe {
  display: flex;
  flex-direction: column;
  align-items: center;
  cursor: grab;
  touch-action: none;
  user-select: none;
}
.map-wrap.globe.dragging {
  cursor: grabbing;
}
.map-svg {
  width: 100%;
  height: auto;
  display: block;
  max-height: 72vh;
}
.map-wrap.globe .map-svg {
  max-width: 540px;
}
.prov {
  cursor: pointer;
}
.num {
  font-size: 11px;
  fill: #1a2a1c;
  font-family: Georgia, 'Times New Roman', serif;
  opacity: 0.92;
  paint-order: stroke;
  stroke: rgba(255, 248, 232, 0.7);
  stroke-width: 3px;
}
.note {
  margin: 0.45rem 0 0;
  font-size: 0.78rem;
  color: #5a6b4e;
  text-align: center;
}
.side {
  border: 1px solid #c5d0b5;
  background: #f7f4ea;
  border-radius: 3px;
  padding: 0.75rem;
}
h2 {
  margin: 0 0 0.45rem;
  font-size: 0.95rem;
  color: #1f3d28;
}
.legend,
.mini {
  list-style: none;
  margin: 0 0 0.85rem;
  padding: 0;
  font-size: 0.8rem;
  color: #3e4a36;
}
.legend li,
.mini li {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.28rem;
}
.swatch {
  width: 14px;
  height: 14px;
  border-radius: 2px;
  border: 1px solid #6a7760;
  flex-shrink: 0;
}
.swatch.sm {
  width: 10px;
  height: 10px;
}
.card {
  font-size: 0.88rem;
}
.card p {
  margin: 0.2rem 0;
}
.ok {
  color: #2f5d3a;
}
.muted {
  color: #6a7760;
  font-size: 0.85rem;
}
.hint {
  margin-top: 0.75rem;
  font-size: 0.78rem;
  color: #6a7760;
  line-height: 1.35;
}
</style>
