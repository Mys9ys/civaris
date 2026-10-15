<script setup>
/**
 * Отрисовка зданий на метрической карте (план + двери-дуги + flip).
 */
import { computed } from 'vue'
import { firmLogoSrc } from '../icons/firmLogo'

const props = defineProps({
  plot: { type: Object, required: true },
  selected: { type: Boolean, default: false },
  ghost: { type: Boolean, default: false },
})

/** Свет двери ~0,5 м; дуга читается на плане. */
const DOOR_W = 0.5

/** Профиль улицы 16 м: тротуар 2 + клумба 2 + 4×2 полосы + клумба 2 + тротуар 2. */
const ROAD_SIDEWALK_M = 2
const ROAD_FLOWER_M = 2
const ROAD_LANE_M = 2
const ROAD_LANES_PER_DIR = 2

function isRoadCross(plot) {
  return (
    plot?.road_mode === 'cross' ||
    plot?.template_key === 'road_cross' ||
    /перекр/i.test(String(plot?.title || ''))
  )
}

const firmLogo = computed(() => firmLogoSrc(props.plot.company_code))

/**
 * Разметка дороги: прямая (вдоль X) или перекрёсток (заходы с 4 сторон, центр пустой).
 * Каждая линия: { key, cls, x1, y1, x2, y2 }.
 */
const roadDashes = computed(() => {
  if (props.plot.kind !== 'road') return []
  const w = Number(props.plot.width_m) || 16
  const d = Number(props.plot.depth_m) || 16
  const pad = 0.15
  const edge = ROAD_SIDEWALK_M + ROAD_FLOWER_M // 4 м — тротуар+клумба
  const lane = ROAD_LANE_M
  const roadway = lane * ROAD_LANES_PER_DIR * 2 // 8 м
  const cross = isRoadCross(props.plot)

  const yMarks = []
  const xMarks = []
  // границы зон + полосы (профиль)
  yMarks.push({ key: 'fn', at: ROAD_SIDEWALK_M, cls: 'road-flower' })
  yMarks.push({ key: 'rn', at: edge, cls: 'road-carriage' })
  for (let i = 1; i < ROAD_LANES_PER_DIR * 2; i++) {
    yMarks.push({
      key: `lane${i}`,
      at: edge + i * lane,
      cls: i === ROAD_LANES_PER_DIR ? 'road-axis' : 'road-lane',
    })
  }
  yMarks.push({ key: 'rf', at: edge + roadway, cls: 'road-carriage' })
  yMarks.push({ key: 'ff', at: edge + roadway + ROAD_FLOWER_M, cls: 'road-flower' })

  // те же отметки по X для перекрёстка (движение N–S)
  for (const m of yMarks) {
    xMarks.push({ ...m, key: `v-${m.key}` })
  }

  if (!cross) {
    // Прямая: пунктиры на всю длину вдоль X
    return yMarks.map((m) => ({
      key: m.key,
      cls: m.cls,
      x1: pad,
      y1: m.at,
      x2: w - pad,
      y2: m.at,
    }))
  }

  // Перекрёсток: заходы с 4 сторон до края проезжей; центр 8×8 без сквозных линий
  const j0x = edge
  const j1x = edge + roadway
  const j0y = edge
  const j1y = edge + roadway
  const lines = []

  for (const m of yMarks) {
    // западный заход
    lines.push({
      key: `${m.key}-w`,
      cls: m.cls,
      x1: pad,
      y1: m.at,
      x2: j0x,
      y2: m.at,
    })
    // восточный заход
    lines.push({
      key: `${m.key}-e`,
      cls: m.cls,
      x1: j1x,
      y1: m.at,
      x2: w - pad,
      y2: m.at,
    })
  }
  for (const m of xMarks) {
    // северный заход
    lines.push({
      key: `${m.key}-n`,
      cls: m.cls,
      x1: m.at,
      y1: pad,
      x2: m.at,
      y2: j0y,
    })
    // южный заход
    lines.push({
      key: `${m.key}-s`,
      cls: m.cls,
      x1: m.at,
      y1: j1y,
      x2: m.at,
      y2: d - pad,
    })
  }

  // Лёгкая рамка перекрёстка (углы тротуара читаются)
  lines.push(
    { key: 'jx-n', cls: 'road-junction', x1: j0x, y1: j0y, x2: j1x, y2: j0y },
    { key: 'jx-s', cls: 'road-junction', x1: j0x, y1: j1y, x2: j1x, y2: j1y },
    { key: 'jx-w', cls: 'road-junction', x1: j0x, y1: j0y, x2: j0x, y2: j1y },
    { key: 'jx-e', cls: 'road-junction', x1: j1x, y1: j0y, x2: j1x, y2: j1y }
  )

  return lines
})

/** Логотип фирмы в углу — без зеркалирования при flip. */
function firmBadgeTransform() {
  const s = 1.15
  const bx = 0.18
  const by = 0.18
  const sx = props.plot.flip_x ? -1 : 1
  const sy = props.plot.flip_y ? -1 : 1
  if (sx === 1 && sy === 1) return `translate(${bx}, ${by})`
  const cx = bx + s / 2
  const cy = by + s / 2
  return `translate(${cx}, ${cy}) scale(${sx}, ${sy}) translate(${-cx}, ${-cy}) translate(${bx}, ${by})`
}

const rootTransform = computed(() => {
  const p = props.plot
  const cx = (Number(p.width_m) || 0) / 2
  const cy = (Number(p.depth_m) || 0) / 2
  const rot = Number(p.rotation_deg) || 0
  const sx = p.flip_x ? -1 : 1
  const sy = p.flip_y ? -1 : 1
  return `translate(${p.x_m}, ${p.y_m}) translate(${cx}, ${cy}) rotate(${rot}) scale(${sx}, ${sy}) translate(${-cx}, ${-cy})`
})

function labelFix(el) {
  const sx = props.plot.flip_x ? -1 : 1
  const sy = props.plot.flip_y ? -1 : 1
  if (sx === 1 && sy === 1) return undefined
  const lx = Number(el.x_m) + Number(el.w_m) / 2
  const ly = Number(el.y_m) + Number(el.d_m) / 2
  return `translate(${lx}, ${ly}) scale(${sx}, ${sy}) translate(${-lx}, ${-ly})`
}

function titleFix() {
  const sx = props.plot.flip_x ? -1 : 1
  const sy = props.plot.flip_y ? -1 : 1
  if (sx === 1 && sy === 1) return undefined
  const lx = Number(props.plot.width_m) / 2
  const ly = Number(props.plot.depth_m) / 2
  return `translate(${lx}, ${ly}) scale(${sx}, ${sy}) translate(${-lx}, ${-ly})`
}

/** Нормализует door: строка 's'|'n'|… или объект {wall,hinge,swing,exit}. */
function normalizeDoor(raw) {
  if (!raw) return null
  if (typeof raw === 'string') {
    return { wall: raw, hinge: 'w', swing: 'out', exit: false }
  }
  if (raw.wall) {
    return {
      wall: raw.wall,
      hinge: raw.hinge === 'e' || raw.hinge === 's' || raw.hinge === 'n' ? raw.hinge : 'w',
      swing: raw.swing === 'in' ? 'in' : 'out',
      exit: !!raw.exit,
    }
  }
  return null
}

/**
 * Полотно + сектор дуги (куда открывается).
 * swing out = из помещения в коридор / во двор.
 */
function doorGeom(el) {
  const door = normalizeDoor(el.door)
  if (!door) return null

  const x = Number(el.x_m) || 0
  const y = Number(el.y_m) || 0
  const w = Number(el.w_m) || 0
  const d = Number(el.d_m) || 0
  const r = DOOR_W
  const wall = door.wall

  let hx
  let hy
  let closedDx
  let closedDy
  let openDx
  let openDy
  let sweep

  if (wall === 's') {
    const cx = x + w / 2
    hy = y + d
    hx = door.hinge === 'e' ? cx + r / 2 : cx - r / 2
    closedDx = door.hinge === 'e' ? -r : r
    closedDy = 0
    const out = door.swing !== 'in'
    openDx = 0
    openDy = out ? r : -r
    sweep = door.hinge === 'e' ? (out ? 0 : 1) : out ? 1 : 0
  } else if (wall === 'n') {
    const cx = x + w / 2
    hy = y
    hx = door.hinge === 'e' ? cx + r / 2 : cx - r / 2
    closedDx = door.hinge === 'e' ? -r : r
    closedDy = 0
    const out = door.swing !== 'in'
    openDx = 0
    openDy = out ? -r : r
    sweep = door.hinge === 'e' ? (out ? 1 : 0) : out ? 0 : 1
  } else if (wall === 'e') {
    const cy = y + d / 2
    hx = x + w
    hy = door.hinge === 's' ? cy + r / 2 : cy - r / 2
    closedDx = 0
    closedDy = door.hinge === 's' ? -r : r
    const out = door.swing !== 'in'
    openDx = out ? r : -r
    openDy = 0
    sweep = door.hinge === 's' ? (out ? 1 : 0) : out ? 0 : 1
  } else if (wall === 'w') {
    const cy = y + d / 2
    hx = x
    hy = door.hinge === 's' ? cy + r / 2 : cy - r / 2
    closedDx = 0
    closedDy = door.hinge === 's' ? -r : r
    const out = door.swing !== 'in'
    openDx = out ? -r : r
    openDy = 0
    sweep = door.hinge === 's' ? (out ? 0 : 1) : out ? 1 : 0
  } else {
    return null
  }

  const ox = hx + openDx
  const oy = hy + openDy
  const cx2 = hx + closedDx
  const cy2 = hy + closedDy

  return {
    hingeX: hx,
    hingeY: hy,
    leafX: ox,
    leafY: oy,
    arcD: `M ${cx2} ${cy2} A ${r} ${r} 0 0 ${sweep} ${ox} ${oy}`,
    sectorD: `M ${hx} ${hy} L ${cx2} ${cy2} A ${r} ${r} 0 0 ${sweep} ${ox} ${oy} Z`,
    exit: door.exit,
  }
}
</script>

<template>
  <g
    :class="[
      'plot-g',
      plot.kind,
      {
        selected,
        ghost,
        pinned: plot.is_pinned,
        'build-site': plot.build_status === 'building',
      },
    ]"
    :transform="rootTransform"
  >
    <rect
      class="shell"
      x="0"
      y="0"
      :width="plot.width_m"
      :height="plot.depth_m"
    />
    <!-- Дорога: пунктиры профиля (прямая / перекрёсток) -->
    <g v-if="plot.kind === 'road'" class="road-marks" pointer-events="none">
      <line
        v-for="ln in roadDashes"
        :key="ln.key"
        :class="ln.cls"
        :x1="ln.x1"
        :y1="ln.y1"
        :x2="ln.x2"
        :y2="ln.y2"
      />
    </g>

    <template v-if="plot.floor_plan?.elements?.length">
      <g
        v-for="el in plot.floor_plan.elements"
        :key="el.id"
        :class="['fp', el.type]"
      >
        <rect
          :x="el.x_m"
          :y="el.y_m"
          :width="el.w_m"
          :height="el.d_m"
        />
        <text
          v-if="el.label"
          :x="el.x_m + el.w_m / 2"
          :y="el.y_m + el.d_m / 2"
          class="fp-label"
          :font-size="Math.min(0.55, Math.min(el.w_m, el.d_m) * 0.28)"
          :transform="labelFix(el)"
        >
          {{ el.label }}
        </text>
        <g v-if="doorGeom(el)" :class="['door-g', { exit: doorGeom(el).exit }]">
          <path class="door-sector" :d="doorGeom(el).sectorD" />
          <path class="door-arc" :d="doorGeom(el).arcD" />
          <line
            class="door-leaf"
            :x1="doorGeom(el).hingeX"
            :y1="doorGeom(el).hingeY"
            :x2="doorGeom(el).leafX"
            :y2="doorGeom(el).leafY"
          />
        </g>
      </g>
    </template>

    <text
      v-else
      class="title"
      :x="plot.width_m / 2"
      :y="plot.depth_m / 2"
      :font-size="Math.min(1.1, Math.min(plot.width_m, plot.depth_m) * 0.22)"
      :transform="titleFix()"
    >
      {{
        plot.kind === 'dorm'
          ? `${plot.occupied ?? 0}/${plot.capacity ?? 0}`
          : plot.kind === 'road'
            ? ''
            : (plot.title || '')
      }}
    </text>

    <!-- Логотип привязанной фирмы — левый верхний угол -->
    <g
      v-if="firmLogo && plot.kind !== 'road'"
      class="firm-badge"
      pointer-events="none"
      :transform="firmBadgeTransform()"
    >
      <rect class="firm-badge-bg" x="0" y="0" width="1.15" height="1.15" rx="0.16" />
      <image
        class="firm-badge-img"
        :href="firmLogo"
        x="0.1"
        y="0.1"
        width="0.95"
        height="0.95"
        preserveAspectRatio="xMidYMid meet"
      />
    </g>

    <!-- Статус стройки: каска + молоток в углу -->
    <g
      v-if="plot.build_status === 'building' && plot.kind !== 'road'"
      class="build-badge"
      pointer-events="none"
      :transform="`translate(${Math.max(0.15, plot.width_m - 1.35)}, 0.15)`"
    >
      <rect class="build-badge-bg" x="0" y="0" width="1.2" height="1.2" rx="0.18" />
      <!-- каска -->
      <path
        class="build-hat"
        d="M0.22 0.55h0.76v0.12H0.22zM0.28 0.55c0-0.22 0.14-0.38 0.34-0.38s0.34 0.16 0.34 0.38"
      />
      <!-- молоток -->
      <path class="build-hammer-handle" d="M0.42 1.05 L0.78 0.68" />
      <path class="build-hammer-head" d="M0.68 0.52 l0.22 0.22 -0.08 0.08 -0.22 -0.22 z" />
    </g>

    <title>
      {{ plot.title }} · {{ plot.width_m }}×{{ plot.depth_m }} м
      <template v-if="plot.rotation_deg"> · {{ plot.rotation_deg }}°</template>
      <template v-if="plot.flip_x || plot.flip_y"> · инверсия</template>
      <template v-if="plot.build_status === 'building'"> · строится</template>
      <template v-if="plot.company_code"> · {{ plot.company_code }}</template>
    </title>
  </g>
</template>

<style scoped>
.shell {
  fill: #e8dcc8;
  stroke: #6b4e32;
  stroke-width: 0.14;
}
.plot-g.dorm .shell {
  fill: #d9c4a4;
}
.plot-g.civic .shell {
  fill: #d4c0a0;
}
.plot-g.build-site .shell {
  fill: #e8dfc8;
  stroke: #8a6a28;
  stroke-dasharray: 0.35 0.22;
}
.build-badge-bg {
  fill: #f0c040;
  stroke: #1f3d28;
  stroke-width: 0.08;
  opacity: 0.95;
}
.build-hat {
  fill: #f0c040;
  stroke: #1f3d28;
  stroke-width: 0.07;
  stroke-linejoin: round;
}
.build-hammer-handle {
  fill: none;
  stroke: #8b5a2b;
  stroke-width: 0.12;
  stroke-linecap: round;
}
.build-hammer-head {
  fill: #8a9099;
  stroke: #1f3d28;
  stroke-width: 0.06;
}
.plot-g.shop .shell {
  fill: #e0c988;
}
.plot-g.firm .shell {
  fill: #f2d9b8;
  stroke: #a0683a;
  stroke-width: 0.16;
  stroke-dasharray: 0.4 0.2;
}
.plot-g.road .shell {
  fill: #f7f5ec;
  fill-opacity: 0.35;
  stroke: #8a7a5c;
  stroke-width: 0.1;
  stroke-dasharray: 0.55 0.4;
}
.plot-g.road.selected .shell {
  fill: #f0e6d4;
  fill-opacity: 0.55;
  stroke: #d9480f;
  stroke-dasharray: none;
}
.plot-g.road .title {
  display: none;
}
.road-marks .road-flower {
  stroke: #6b8f5a;
  stroke-width: 0.12;
  stroke-dasharray: 0.45 0.35;
  opacity: 0.9;
}
.road-marks .road-carriage {
  stroke: #5a5248;
  stroke-width: 0.16;
  stroke-dasharray: 0.7 0.4;
  opacity: 0.95;
}
.road-marks .road-lane {
  stroke: #8a8278;
  stroke-width: 0.08;
  stroke-dasharray: 0.35 0.45;
  opacity: 0.7;
}
.firm-badge-bg {
  fill: #fffcf5;
  stroke: #1f3d28;
  stroke-width: 0.07;
  opacity: 0.95;
}
.firm-badge-img {
  pointer-events: none;
}
.road-marks .road-junction {
  stroke: #8a7a55;
  stroke-width: 0.1;
  stroke-dasharray: 0.35 0.22;
  opacity: 0.55;
}
.road-marks .road-axis {
  stroke: #5a5248;
  stroke-width: 0.14;
  stroke-dasharray: 1.1 0.55;
  opacity: 0.9;
}
.plot-g.selected .shell {
  stroke: #d9480f;
  stroke-width: 0.28;
  stroke-dasharray: none;
}
.plot-g.ghost {
  opacity: 0.6;
}
.plot-g.pinned .shell {
  stroke: #8a5a2b;
}
.plot-g {
  cursor: pointer;
}
.plot-g.selected {
  cursor: grab;
}
.fp rect {
  fill: #f3ebe0;
  stroke: #6b4e32;
  stroke-width: 0.08;
}
.fp.corridor rect,
.fp.hall rect {
  fill: #c4a574;
  stroke: none;
}
.fp.room rect {
  fill: #f6efe6;
  stroke: #6b4e32;
  stroke-width: 0.07;
}
.fp-label,
.title {
  fill: #4a3420;
  text-anchor: middle;
  dominant-baseline: middle;
  font-family: system-ui, sans-serif;
  font-weight: 600;
  pointer-events: none;
}
.door-sector {
  fill: #6b4e32;
  fill-opacity: 0.18;
  stroke: none;
}
.door-leaf {
  stroke: #6b4e32;
  stroke-width: 0.13;
  stroke-linecap: round;
}
.door-arc {
  fill: none;
  stroke: #6b4e32;
  stroke-width: 0.09;
  opacity: 0.95;
}
.door-g.exit .door-sector {
  fill-opacity: 0.28;
}
.door-g.exit .door-leaf {
  stroke-width: 0.15;
}
.door-g.exit .door-arc {
  stroke-width: 0.11;
}
</style>
