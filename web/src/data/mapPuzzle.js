/**
 * Civaris — провинции-пазлы 16×16 (сектора 4×4)
 * Стыковка краёв как Carcassonne; каталог + сборка 8×5.
 * Канон: docs/map-puzzle-rules.md
 */

export const MAP_SMALL = {
  key: 'small',
  label: 'Малая',
  cols: 8,
  rows: 5,
  provinceCount: 40,
  startCount: 5,
  maxCitiesPerCountry: 8,
  tileSize: 16,
  sectorSize: 4,
  sectorsPerSide: 4,
}

export const START_COLORS = ['#2f6b4a', '#8a5a2b', '#2f4f7a', '#7a3d5c', '#5a6b2f']

export const TERRAIN = {
  plain: { key: 'plain', ru: 'равнины / поля', fill: '#b7d39a', family: 'land' },
  beach: { key: 'beach', ru: 'пляж', fill: '#e6d5a8', family: 'land' },
  river: { key: 'river', ru: 'река', fill: '#4a8fb8', family: 'water' },
  pond: { key: 'pond', ru: 'пруд', fill: '#3a7ea0', family: 'water' },
  lake: { key: 'lake', ru: 'озеро', fill: '#3a7ea0', family: 'water' },
  sea: { key: 'sea', ru: 'море', fill: '#3a7ea0', family: 'water' },
  square: { key: 'square', ru: 'сквер', fill: '#2f6b3a', family: 'green' },
  park: { key: 'park', ru: 'парк', fill: '#2f6b3a', family: 'green' },
  forest: { key: 'forest', ru: 'лес', fill: '#2f6b3a', family: 'green' },
  ridge: { key: 'ridge', ru: 'гряда / сопка', fill: '#7a756e', family: 'rock' },
  mountain: { key: 'mountain', ru: 'горы / массив', fill: '#7a756e', family: 'rock' },
}

export function terrainFill(t) {
  return TERRAIN[t]?.fill || '#cfd6c4'
}

export function politicalFill(p) {
  if (p.startIndex) return START_COLORS[p.startIndex - 1]
  return '#cfd6c4'
}

function hash01(n) {
  const x = Math.sin(n * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}

function lerp(a, b, t) {
  return a + (b - a) * t
}

function fade(t) {
  return t * t * (3 - 2 * t)
}

function valueNoise2D(x, y, seed) {
  const xi = Math.floor(x)
  const yi = Math.floor(y)
  const xf = x - xi
  const yf = y - yi
  const u = fade(xf)
  const v = fade(yf)
  const n00 = hash01(seed + xi * 374761 + yi * 668265)
  const n10 = hash01(seed + (xi + 1) * 374761 + yi * 668265)
  const n01 = hash01(seed + xi * 374761 + (yi + 1) * 668265)
  const n11 = hash01(seed + (xi + 1) * 374761 + (yi + 1) * 668265)
  return lerp(lerp(n00, n10, u), lerp(n01, n11, u), v)
}

function fbm(x, y, seed, octaves = 3) {
  let amp = 1
  let freq = 1
  let sum = 0
  let norm = 0
  for (let o = 0; o < octaves; o++) {
    sum += amp * valueNoise2D(x * freq, y * freq, seed + o * 97)
    norm += amp
    amp *= 0.5
    freq *= 2
  }
  return sum / norm
}

function neighbors4(x, y, N) {
  const out = []
  if (x > 0) out.push([x - 1, y])
  if (x < N - 1) out.push([x + 1, y])
  if (y > 0) out.push([x, y - 1])
  if (y < N - 1) out.push([x, y + 1])
  return out
}

function blankGrid(N = 16) {
  return Array.from({ length: N }, () => Array(N).fill('plain'))
}

function edgePoint(side, slot, N = 16) {
  const s = Math.max(0, Math.min(N - 1, slot))
  if (side === 'n') return [s, 0]
  if (side === 's') return [s, N - 1]
  if (side === 'w') return [0, s]
  return [N - 1, s]
}

function rotateEdgeCW(side, edge, N = 16) {
  const order = ['n', 'e', 's', 'w']
  const i = order.indexOf(side)
  const next = order[(i + 1) % 4]
  let slot = edge.slot
  if (slot != null && (side === 'n' || side === 's')) slot = N - 1 - slot
  return { side: next, edge: { kind: edge.kind, slot } }
}

function rotateEdgesCW(edges, times, N = 16) {
  let cur = {
    n: { ...edges.n },
    e: { ...edges.e },
    s: { ...edges.s },
    w: { ...edges.w },
  }
  for (let t = 0; t < times; t++) {
    const next = {}
    for (const side of ['n', 'e', 's', 'w']) {
      const { side: ns, edge } = rotateEdgeCW(side, cur[side], N)
      next[ns] = edge
    }
    cur = next
  }
  return cur
}

function rotateGridCW(grid) {
  const N = grid.length
  const out = blankGrid(N)
  for (let y = 0; y < N; y++) {
    for (let x = 0; x < N; x++) out[x][N - 1 - y] = grid[y][x]
  }
  return out
}

function edgesMatch(a, b) {
  if (!a || !b) return true
  if (a.kind !== b.kind) return false
  if (a.kind === 'river') {
    if (a.slot == null || b.slot == null) return true
    return a.slot === b.slot
  }
  return true
}

function opposite(side) {
  return { n: 's', s: 'n', e: 'w', w: 'e' }[side]
}

function paintBlob(grid, cx, cy, type, target, seed, opts = {}) {
  const N = grid.length
  const maxR = opts.maxR || 5
  const cells = []
  for (let y = 0; y < N; y++) {
    for (let x = 0; x < N; x++) {
      const dx = (x - cx) / maxR
      const dy = (y - cy) / maxR
      const dist = Math.sqrt(dx * dx + dy * dy)
      const n = fbm(x * 0.35 + seed, y * 0.35, seed + 3, 3)
      if (dist < 0.35 + n * 0.75 && grid[y][x] === 'plain') {
        cells.push({ x, y, score: n - dist })
      }
    }
  }
  cells.sort((a, b) => b.score - a.score)
  let painted = 0
  for (const c of cells) {
    if (painted >= target) break
    grid[c.y][c.x] = type
    painted++
  }
  return painted
}

function paintRiver(grid, x0, y0, x1, y1, seed, width = 1) {
  const N = grid.length
  const steps = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0), 8) * 2
  for (let i = 0; i <= steps; i++) {
    const t = i / steps
    let x = lerp(x0, x1, t)
    let y = lerp(y0, y1, t)
    const wob = (fbm(t * 4, seed * 0.01, seed, 3) - 0.5) * 2.2
    const dx = x1 - x0
    const dy = y1 - y0
    const len = Math.hypot(dx, dy) || 1
    x += (-dy / len) * wob
    y += (dx / len) * wob
    const xi = Math.round(x)
    const yi = Math.round(y)
    const offs = width === 1 ? [[0, 0]] : [[0, 0], [1, 0], [0, 1]]
    for (const [ox, oy] of offs) {
      const xx = xi + ox
      const yy = yi + oy
      if (xx < 0 || yy < 0 || xx >= N || yy >= N) continue
      if (grid[yy][xx] === 'sea' || grid[yy][xx] === 'lake') continue
      grid[yy][xx] = 'river'
    }
  }
}

const CORNER_SECTORS = {
  nw: { x0: 0, y0: 0, x1: 4, y1: 4 },
  ne: { x0: 12, y0: 0, x1: 16, y1: 4 },
  sw: { x0: 0, y0: 12, x1: 4, y1: 16 },
  se: { x0: 12, y0: 12, x1: 16, y1: 16 },
}

function sectorCells(corner, N = 16) {
  const s = CORNER_SECTORS[corner]
  const out = []
  for (let y = s.y0; y < s.y1 && y < N; y++) {
    for (let x = s.x0; x < s.x1 && x < N; x++) out.push([x, y])
  }
  return out
}

function countTerrainInSector(grid, corner, terrain) {
  let n = 0
  for (const [x, y] of sectorCells(corner)) if (grid[y][x] === terrain) n++
  return n
}

function paintSector(grid, corner, terrain, overwrite = ['plain', 'river', 'pond', 'beach']) {
  for (const [x, y] of sectorCells(corner)) {
    if (overwrite.includes(grid[y][x])) grid[y][x] = terrain
  }
}

function hasRiver(grid) {
  for (const row of grid) if (row.includes('river')) return true
  return false
}

function stripInteriorBeach(grid) {
  const N = grid.length
  for (let y = 0; y < N; y++) {
    for (let x = 0; x < N; x++) {
      if (grid[y][x] !== 'beach') continue
      const seaN = neighbors4(x, y, N).filter(([nx, ny]) => grid[ny][nx] === 'sea')
      const landN = neighbors4(x, y, N).filter(([nx, ny]) => grid[ny][nx] === 'plain' || grid[ny][nx] === 'beach')
      if (seaN.length === 0 || landN.length === 0) grid[y][x] = 'sea'
    }
  }
}

function stripStrayPonds(grid) {
  if (!hasRiver(grid)) return
  const N = grid.length
  for (let y = 0; y < N; y++) {
    for (let x = 0; x < N; x++) {
      if (grid[y][x] !== 'pond') continue
      const touches = neighbors4(x, y, N).some(([nx, ny]) => grid[ny][nx] === 'river')
      if (!touches) grid[y][x] = 'plain'
    }
  }
}

function paintMonolithicSea(grid, side, depth, seed) {
  const N = grid.length
  if (side === 'n') {
    for (let y = 0; y < depth; y++) for (let x = 0; x < N; x++) grid[y][x] = 'sea'
  } else if (side === 's') {
    for (let y = N - depth; y < N; y++) for (let x = 0; x < N; x++) grid[y][x] = 'sea'
  } else if (side === 'w') {
    for (let x = 0; x < depth; x++) for (let y = 0; y < N; y++) grid[y][x] = 'sea'
  } else if (side === 'e') {
    for (let x = N - depth; x < N; x++) for (let y = 0; y < N; y++) grid[y][x] = 'sea'
  }
}

function fillSeaBlob(grid, x0, y0, w, h) {
  const N = grid.length
  for (let y = y0; y < y0 + h && y < N; y++) {
    for (let x = x0; x < x0 + w && x < N; x++) grid[y][x] = 'sea'
  }
}

function paintBeaches(grid) {
  const N = grid.length
  for (let y = 0; y < N; y++) {
    for (let x = 0; x < N; x++) {
      if (grid[y][x] !== 'plain') continue
      const seaN = neighbors4(x, y, N).filter(([nx, ny]) => grid[ny][nx] === 'sea')
      if (seaN.length === 0) continue
      if (seaN.length >= 3) continue
      grid[y][x] = 'beach'
    }
  }
}

function scanRiverSlot(grid, side, N = 16) {
  const slots = []
  for (let i = 0; i < N; i++) {
    const [x, y] = edgePoint(side, i, N)
    if (grid[y][x] === 'river') slots.push(i)
  }
  if (!slots.length) return null
  return slots[Math.floor(slots.length / 2)]
}

function scanRiverNearEdge(grid, side, N = 16) {
  const inward = { n: [0, 1], s: [0, -1], e: [-1, 0], w: [1, 0] }
  const [dx, dy] = inward[side]
  for (let slot = 0; slot < N; slot++) {
    const [x0, y0] = edgePoint(side, slot, N)
    for (let step = 0; step < 4; step++) {
      const x = x0 + dx * step
      const y = y0 + dy * step
      if (x < 0 || y < 0 || x >= N || y >= N) break
      if (grid[y][x] === 'river') return slot
    }
  }
  return null
}

function edgeSeaCoverage(grid, side, N = 16) {
  let n = 0
  for (let i = 0; i < N; i++) {
    const [x, y] = edgePoint(side, i, N)
    if (grid[y][x] === 'sea') n++
  }
  return n
}

function applySeaEdgeRounding(placed, rows, cols, N = 16) {
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const g = placed[r][c].grid
      if (edgeSeaCoverage(g, 'n', N) >= 6 && r + 1 < rows) {
        paintSector(placed[r + 1][c].grid, 'nw', 'sea')
        paintSector(placed[r + 1][c].grid, 'ne', 'sea')
      }
      if (edgeSeaCoverage(g, 's', N) >= 6 && r > 0) {
        paintSector(placed[r - 1][c].grid, 'sw', 'sea')
        paintSector(placed[r - 1][c].grid, 'se', 'sea')
      }
      if (edgeSeaCoverage(g, 'w', N) >= 6 && c + 1 < cols) {
        paintSector(placed[r][c + 1].grid, 'nw', 'sea')
        paintSector(placed[r][c + 1].grid, 'sw', 'sea')
      }
      if (edgeSeaCoverage(g, 'e', N) >= 6 && c > 0) {
        paintSector(placed[r][c - 1].grid, 'ne', 'sea')
        paintSector(placed[r][c - 1].grid, 'se', 'sea')
      }
    }
  }
}

function ensureRiverOnEdge(grid, side, slot, N = 16) {
  const inward = { n: [0, 1], s: [0, -1], e: [-1, 0], w: [1, 0] }
  const [dx, dy] = inward[side]
  const [x0, y0] = edgePoint(side, slot, N)
  for (let step = 0; step <= 4; step++) {
    const x = x0 + dx * step
    const y = y0 + dy * step
    if (x < 0 || y < 0 || x >= N || y >= N) continue
    if (grid[y][x] === 'sea' || grid[y][x] === 'lake') break
    grid[y][x] = 'river'
  }
}

function syncRiverBorder(a, b, sideA, N = 16) {
  const sideB = opposite(sideA)
  const wantRiver =
    a.edges[sideA]?.kind === 'river' ||
    b.edges[sideB]?.kind === 'river' ||
    scanRiverSlot(a.grid, sideA, N) != null ||
    scanRiverSlot(b.grid, sideB, N) != null ||
    scanRiverNearEdge(a.grid, sideA, N) != null ||
    scanRiverNearEdge(b.grid, sideB, N) != null
  if (!wantRiver) return

  const slot =
    scanRiverSlot(a.grid, sideA, N) ??
    scanRiverSlot(b.grid, sideB, N) ??
    scanRiverNearEdge(a.grid, sideA, N) ??
    scanRiverNearEdge(b.grid, sideB, N) ??
    a.edges[sideA]?.slot ??
    b.edges[sideB]?.slot ??
    8

  a.edges[sideA] = { kind: 'river', slot }
  b.edges[sideB] = { kind: 'river', slot }
  ensureRiverOnEdge(a.grid, sideA, slot, N)
  ensureRiverOnEdge(b.grid, sideB, slot, N)
  const bridgeA = scanRiverNearEdge(a.grid, sideA, N)
  const bridgeB = scanRiverNearEdge(b.grid, sideB, N)
  if (bridgeA != null && bridgeA !== slot) ensureRiverOnEdge(a.grid, sideA, bridgeA, N)
  if (bridgeB != null && bridgeB !== slot) ensureRiverOnEdge(b.grid, sideB, bridgeB, N)
}

function applyCornerRounding(placed, rows, cols, terrain, minInCorner = 3) {
  const corners = ['nw', 'ne', 'sw', 'se']
  const toward = {
    nw: { n: 'sw', w: 'ne', nw: 'se' },
    ne: { n: 'se', e: 'nw', ne: 'sw' },
    sw: { s: 'nw', w: 'se', sw: 'ne' },
    se: { s: 'ne', e: 'sw', se: 'nw' },
  }
  const neighborAt = {
    n: (r, c) => [r - 1, c],
    s: (r, c) => [r + 1, c],
    e: (r, c) => [r, c + 1],
    w: (r, c) => [r, c - 1],
    nw: (r, c) => [r - 1, c - 1],
    ne: (r, c) => [r - 1, c + 1],
    sw: (r, c) => [r + 1, c - 1],
    se: (r, c) => [r + 1, c + 1],
  }

  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const cur = placed[r][c]
      for (const corner of corners) {
        if (countTerrainInSector(cur.grid, corner, terrain) < minInCorner) continue
        for (const [dir, theirCorner] of Object.entries(toward[corner])) {
          const [nr, nc] = neighborAt[dir](r, c)
          if (nr < 0 || nc < 0 || nr >= rows || nc >= cols) continue
          paintSector(placed[nr][nc].grid, theirCorner, terrain)
        }
      }
    }
  }
}

function postProcessMap(placed, rows, cols, seed) {
  const N = 16
  for (let pass = 0; pass < 2; pass++) {
    for (let r = 0; r < rows; r++) {
      for (let c = 0; c < cols; c++) {
        if (r > 0) syncRiverBorder(placed[r][c], placed[r - 1][c], 'n', N)
        if (c > 0) syncRiverBorder(placed[r][c], placed[r][c - 1], 'w', N)
      }
    }
  }

  applyCornerRounding(placed, rows, cols, 'sea', 2)
  applySeaEdgeRounding(placed, rows, cols, N)
  applyCornerRounding(placed, rows, cols, 'forest', 4)

  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const g = placed[r][c].grid
      stripStrayPonds(g)
      stripInteriorBeach(g)
      paintBeaches(g)
    }
  }
}

export const PUZZLE_RECIPES = [
  {
    id: 'habit_plain',
    label: 'равнина + микс',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'plain' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintBlob(g, 4, 4, 'pond', 8, seed + 1, { maxR: 3 })
      paintBlob(g, 11, 5, 'square', 9, seed + 2, { maxR: 3 })
      paintBlob(g, 8, 11, 'ridge', 10, seed + 3, { maxR: 3 })
      return g
    },
  },
  {
    id: 'river_ew',
    label: 'русло З–В',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'river', slot: 8 },
      s: { kind: 'plain' },
      w: { kind: 'river', slot: 8 },
    },
    build(seed) {
      const g = blankGrid()
      paintRiver(g, 0, 8, 15, 8, seed + 10, 1)
      paintBlob(g, 4, 3, 'square', 8, seed + 11, { maxR: 3 })
      paintBlob(g, 12, 12, 'ridge', 9, seed + 12, { maxR: 3 })
      return g
    },
  },
  {
    id: 'river_ns',
    label: 'русло С–Ю',
    edges: {
      n: { kind: 'river', slot: 8 },
      e: { kind: 'plain' },
      s: { kind: 'river', slot: 8 },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintRiver(g, 8, 0, 8, 15, seed + 20, 1)
      paintBlob(g, 12, 4, 'square', 8, seed + 22, { maxR: 3 })
      return g
    },
  },
  {
    id: 'river_bend_ne',
    label: 'поворот С–В',
    edges: {
      n: { kind: 'river', slot: 9 },
      e: { kind: 'river', slot: 6 },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintRiver(g, 9, 0, 9, 6, seed + 30, 1)
      paintRiver(g, 9, 6, 15, 6, seed + 31, 1)
      paintBlob(g, 4, 11, 'park', 28, seed + 32, { maxR: 5 })
      return g
    },
  },
  {
    id: 'river_bend_ws',
    label: 'поворот З–Ю',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'plain' },
      s: { kind: 'river', slot: 5 },
      w: { kind: 'river', slot: 10 },
    },
    build(seed) {
      const g = blankGrid()
      paintRiver(g, 0, 10, 5, 10, seed + 35, 1)
      paintRiver(g, 5, 10, 5, 15, seed + 36, 1)
      paintBlob(g, 11, 4, 'square', 8, seed + 37, { maxR: 3 })
      return g
    },
  },
  {
    id: 'river_source_ridge',
    label: 'исток с гряды',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'plain' },
      s: { kind: 'river', slot: 8 },
      w: { kind: 'mountain' },
    },
    build(seed) {
      const g = blankGrid()
      for (let i = 0; i < 28; i++) {
        const x = 1 + Math.floor(hash01(seed + 40 + i) * 4)
        const y = 2 + Math.floor(hash01(seed + 50 + i) * 12)
        if (g[y][x] === 'plain') g[y][x] = 'ridge'
      }
      paintRiver(g, 4, 7, 8, 8, seed + 41, 1)
      paintRiver(g, 8, 8, 8, 15, seed + 42, 1)
      paintBlob(g, 12, 4, 'square', 8, seed + 43, { maxR: 3 })
      return g
    },
  },
  {
    id: 'coast_sea_n',
    label: 'берег море С',
    edges: {
      n: { kind: 'sea' },
      e: { kind: 'plain' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintMonolithicSea(g, 'n', 5, seed)
      paintBlob(g, 4, 10, 'square', 8, seed + 51, { maxR: 3 })
      paintBlob(g, 12, 12, 'ridge', 8, seed + 52, { maxR: 3 })
      return g
    },
  },
  {
    id: 'coast_sea_e',
    label: 'берег море В',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'sea' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintMonolithicSea(g, 'e', 6, seed)
      paintBlob(g, 4, 4, 'square', 8, seed + 56, { maxR: 3 })
      paintBlob(g, 5, 11, 'park', 24, seed + 57, { maxR: 4 })
      return g
    },
  },
  {
    id: 'coast_river_mouth',
    label: 'устье в море',
    edges: {
      n: { kind: 'sea' },
      e: { kind: 'plain' },
      s: { kind: 'river', slot: 8 },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintMonolithicSea(g, 'n', 4, seed)
      paintRiver(g, 8, 15, 8, 4, seed + 60, 1)
      paintBlob(g, 3, 10, 'square', 8, seed + 61, { maxR: 3 })
      return g
    },
  },
  {
    id: 'forest_ew',
    label: 'лес З–В',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'forest' },
      s: { kind: 'plain' },
      w: { kind: 'forest' },
    },
    build(seed) {
      const g = blankGrid()
      paintBlob(g, 8, 8, 'forest', 52, seed + 70, { maxR: 7 })
      for (let i = 0; i < 6; i++) {
        const x = 15
        const y = 5 + i
        if (g[y][x] === 'plain') g[y][x] = 'forest'
      }
      for (let i = 0; i < 6; i++) {
        const x = 0
        const y = 5 + i
        if (g[y][x] === 'plain') g[y][x] = 'forest'
      }
      paintBlob(g, 8, 2, 'square', 7, seed + 71, { maxR: 3 })
      paintBlob(g, 8, 13, 'ridge', 8, seed + 72, { maxR: 3 })
      return g
    },
  },
  {
    id: 'forest_ns',
    label: 'лес С–Ю',
    edges: {
      n: { kind: 'forest' },
      e: { kind: 'plain' },
      s: { kind: 'forest' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintBlob(g, 8, 8, 'forest', 48, seed + 75, { maxR: 6 })
      for (let y = 0; y < 4; y++) {
        for (let x = 6; x < 10; x++) if (g[y][x] === 'plain') g[y][x] = 'forest'
      }
      for (let y = 12; y < 16; y++) {
        for (let x = 6; x < 10; x++) if (g[y][x] === 'plain') g[y][x] = 'forest'
      }
      paintBlob(g, 2, 8, 'square', 7, seed + 76, { maxR: 3 })
      paintBlob(g, 13, 8, 'ridge', 8, seed + 77, { maxR: 3 })
      return g
    },
  },
  {
    id: 'forest_corner_ne',
    label: 'лес угол С–В',
    edges: {
      n: { kind: 'forest' },
      e: { kind: 'forest' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintBlob(g, 11, 4, 'forest', 40, seed + 80, { maxR: 5 })
      for (let y = 0; y < 4; y++) {
        for (let x = 10; x < 16; x++) if (g[y][x] === 'plain') g[y][x] = 'forest'
      }
      for (let x = 12; x < 16; x++) {
        for (let y = 0; y < 9; y++) if (g[y][x] === 'plain') g[y][x] = 'forest'
      }
      paintBlob(g, 3, 12, 'square', 8, seed + 81, { maxR: 3 })
      paintBlob(g, 5, 5, 'ridge', 8, seed + 82, { maxR: 3 })
      return g
    },
  },
  {
    id: 'ridge_ew',
    label: 'гряда З–В + исток',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'mountain' },
      s: { kind: 'river', slot: 8 },
      w: { kind: 'mountain' },
    },
    build(seed) {
      const g = blankGrid()
      for (let i = 0; i < 40; i++) {
        const x = Math.floor(hash01(seed + 90 + i) * 16)
        const y = 6 + Math.floor(hash01(seed + 100 + i) * 4)
        if (fbm(x * 0.3, y * 0.3, seed, 2) > 0.28) g[y][x] = 'ridge'
      }
      paintRiver(g, 8, 8, 8, 15, seed + 91, 1)
      paintBlob(g, 3, 3, 'square', 8, seed + 92, { maxR: 3 })
      return g
    },
  },
  {
    id: 'massif_chunk',
    label: 'кусок массива',
    edges: {
      n: { kind: 'mountain' },
      e: { kind: 'mountain' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      for (let y = 0; y < 8; y++) {
        for (let x = 8; x < 16; x++) {
          if (fbm(x * 0.22, y * 0.22, seed + 110, 3) > 0.34) g[y][x] = 'mountain'
        }
      }
      paintRiver(g, 10, 7, 4, 14, seed + 111, 1)
      paintRiver(g, 12, 6, 14, 14, seed + 112, 1)
      paintBlob(g, 3, 4, 'square', 7, seed + 113, { maxR: 3 })
      return g
    },
  },
  {
    id: 'park_local',
    label: 'парк внутри',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'plain' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintBlob(g, 8, 8, 'park', 48, seed + 120, { maxR: 6 })
      paintBlob(g, 3, 3, 'pond', 8, seed + 121, { maxR: 3 })
      paintBlob(g, 13, 12, 'ridge', 8, seed + 122, { maxR: 3 })
      return g
    },
  },
  {
    id: 'river_to_pond',
    label: 'река → пруд',
    edges: {
      n: { kind: 'river', slot: 8 },
      e: { kind: 'plain' },
      s: { kind: 'plain' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintRiver(g, 8, 0, 8, 9, seed + 140, 1)
      paintBlob(g, 8, 12, 'pond', 14, seed + 141, { maxR: 4 })
      paintBlob(g, 3, 4, 'square', 8, seed + 142, { maxR: 3 })
      paintBlob(g, 12, 5, 'ridge', 8, seed + 143, { maxR: 3 })
      return g
    },
  },
  {
    id: 'coast_sea_w',
    label: 'берег море З',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'plain' },
      s: { kind: 'plain' },
      w: { kind: 'sea' },
    },
    build(seed) {
      const g = blankGrid()
      paintMonolithicSea(g, 'w', 6, seed)
      paintBlob(g, 10, 4, 'square', 8, seed + 145, { maxR: 3 })
      paintBlob(g, 11, 12, 'ridge', 8, seed + 146, { maxR: 3 })
      return g
    },
  },
  {
    id: 'lake_edge_s',
    label: 'озеро на юге',
    edges: {
      n: { kind: 'plain' },
      e: { kind: 'plain' },
      s: { kind: 'lake' },
      w: { kind: 'plain' },
    },
    build(seed) {
      const g = blankGrid()
      paintBlob(g, 8, 13, 'lake', 36, seed + 130, { maxR: 5 })
      paintBlob(g, 4, 4, 'square', 8, seed + 131, { maxR: 3 })
      paintBlob(g, 12, 5, 'ridge', 8, seed + 132, { maxR: 3 })
      return g
    },
  },
]

function recipeEdges(recipe) {
  return recipe.edges
}

function instantiateRecipe(recipe, rot, seed) {
  let grid = recipe.build(seed)
  let edges = {
    n: { ...recipeEdges(recipe).n },
    e: { ...recipeEdges(recipe).e },
    s: { ...recipeEdges(recipe).s },
    w: { ...recipeEdges(recipe).w },
  }
  for (let i = 0; i < rot; i++) {
    grid = rotateGridCW(grid)
    edges = rotateEdgesCW(edges, 1)
  }
  return { recipeId: recipe.id, label: recipe.label, rot, edges, grid }
}

function requiredEdge(neighbors, side) {
  const nb = neighbors[side]
  if (!nb) return null
  return nb.edges[opposite(side)]
}

function findFitting(neighbors, seed, attempt) {
  const bag = []
  for (const recipe of PUZZLE_RECIPES) {
    for (let rot = 0; rot < 4; rot++) bag.push({ recipe, rot })
  }
  for (let i = bag.length - 1; i > 0; i--) {
    const j = Math.floor(hash01(seed + attempt * 17 + i) * (i + 1))
    ;[bag[i], bag[j]] = [bag[j], bag[i]]
  }

  for (const { recipe, rot } of bag) {
    const inst = instantiateRecipe(recipe, rot, seed + attempt * 31 + rot * 7)
    let ok = true
    for (const side of ['n', 'e', 's', 'w']) {
      const need = requiredEdge(neighbors, side)
      if (need && !edgesMatch(inst.edges[side], need)) {
        ok = false
        break
      }
    }
    if (ok) return inst
  }

  const fallback = instantiateRecipe(PUZZLE_RECIPES[0], 0, seed + attempt)
  for (const side of ['n', 'e', 's', 'w']) {
    const need = requiredEdge(neighbors, side)
    if (!need) continue
    fallback.edges[side] = { ...need }
    if (need.kind === 'river' && need.slot != null) {
      const [x, y] = edgePoint(side, need.slot)
      const ix = side === 'w' ? 4 : side === 'e' ? 11 : x
      const iy = side === 'n' ? 4 : side === 's' ? 11 : y
      paintRiver(fallback.grid, x, y, ix, iy, seed + 999, 1)
    }
    if (need.kind === 'sea') {
      for (let i = 0; i < 16; i++) {
        const [x, y] = edgePoint(side, i)
        fallback.grid[y][x] = 'sea'
      }
      paintBeaches(fallback.grid)
    }
    if (need.kind === 'forest') {
      for (let i = 0; i < 16; i++) {
        const [x, y] = edgePoint(side, i)
        if (fallback.grid[y][x] === 'plain') fallback.grid[y][x] = 'forest'
      }
    }
    if (need.kind === 'mountain') {
      for (let i = 0; i < 16; i++) {
        const [x, y] = edgePoint(side, i)
        if (fallback.grid[y][x] === 'plain') fallback.grid[y][x] = 'ridge'
      }
    }
    if (need.kind === 'lake') {
      for (let i = 0; i < 16; i++) {
        const [x, y] = edgePoint(side, i)
        if (fallback.grid[y][x] === 'plain') fallback.grid[y][x] = 'lake'
      }
    }
  }
  return fallback
}

function mergeProvinceRects(cells, tileN) {
  const visited = Array.from({ length: tileN }, () => Array(tileN).fill(false))
  const rects = []
  for (let tj = 0; tj < tileN; tj++) {
    for (let ti = 0; ti < tileN; ti++) {
      if (visited[tj][ti]) continue
      const terrain = cells[tj][ti]
      let maxW = 1
      let maxH = 1
      if (terrain !== 'river') {
        while (ti + maxW < tileN && cells[tj][ti + maxW] === terrain && !visited[tj][ti + maxW]) maxW++
        outer: while (tj + maxH < tileN) {
          for (let x = 0; x < maxW; x++) {
            if (cells[tj + maxH][ti + x] !== terrain || visited[tj + maxH][ti + x]) break outer
          }
          maxH++
        }
      }
      for (let y = 0; y < maxH; y++) {
        for (let x = 0; x < maxW; x++) visited[tj + y][ti + x] = true
      }
      rects.push({ terrain, ti0: ti, tj0: tj, ti1: ti + maxW, tj1: tj + maxH })
    }
  }
  return rects
}

function countsOf(cells) {
  const counts = Object.fromEntries(Object.keys(TERRAIN).map((k) => [k, 0]))
  for (const row of cells) for (const t of row) counts[t] = (counts[t] || 0) + 1
  return counts
}

function isHabitable(counts, tileN) {
  const plain = (counts.plain || 0) + (counts.beach || 0)
  const water =
    (counts.sea || 0) +
    (counts.lake || 0) +
    (counts.pond || 0) +
    (counts.river || 0) +
    (counts.beach || 0)
  const total = tileN * tileN
  return plain >= total * 0.12 && water >= 4 && (counts.sea || 0) <= total * 0.4
}

function lerpUV(a, b, t) {
  return { u: lerp(a.u, b.u, t), v: lerp(a.v, b.v, t) }
}
function bilinear(corners, s, t) {
  const [tl, tr, br, bl] = corners
  return lerpUV(lerpUV(tl, tr, s), lerpUV(bl, br, s), t)
}

export function buildSmallProvinces(seed = 42) {
  const { cols, rows, tileSize: tileN } = MAP_SMALL
  const placed = Array.from({ length: rows }, () => Array(cols).fill(null))

  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const neighbors = {
        n: r > 0 ? placed[r - 1][c] : null,
        s: null,
        w: c > 0 ? placed[r][c - 1] : null,
        e: null,
      }
      placed[r][c] = findFitting(neighbors, seed + r * 100 + c * 13, r * cols + c)
    }
  }

  postProcessMap(placed, rows, cols, seed)

  const verts = []
  for (let r = 0; r <= rows; r++) {
    const row = []
    for (let c = 0; c <= cols; c++) row.push({ u: c / cols, v: r / rows, c, r })
    verts.push(row)
  }

  const provinces = []
  const recipeUses = {}

  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const id = r * cols + c + 1
      const inst = placed[r][c]
      recipeUses[inst.recipeId] = (recipeUses[inst.recipeId] || 0) + 1
      const cells = inst.grid
      const counts = countsOf(cells)
      const habitable = isHabitable(counts, tileN)
      const corners = [verts[r][c], verts[r][c + 1], verts[r + 1][c + 1], verts[r + 1][c]]
      const cu = corners.reduce((s, p) => s + p.u, 0) / 4
      const cv = corners.reduce((s, p) => s + p.v, 0) / 4
      const rects = mergeProvinceRects(cells, tileN)
      const patches = rects.map((rect, i) => {
        const tl = bilinear(corners, rect.ti0 / tileN, rect.tj0 / tileN)
        const tr = bilinear(corners, rect.ti1 / tileN, rect.tj0 / tileN)
        const br = bilinear(corners, rect.ti1 / tileN, rect.tj1 / tileN)
        const bl = bilinear(corners, rect.ti0 / tileN, rect.tj1 / tileN)
        return {
          id: `${id}-r${i}`,
          provinceId: id,
          terrain: rect.terrain,
          terrain_ru: TERRAIN[rect.terrain]?.ru || rect.terrain,
          corners: [tl, tr, br, bl],
          cu: (tl.u + tr.u + br.u + bl.u) / 4,
          cv: (tl.v + tr.v + br.v + bl.v) / 4,
        }
      })

      provinces.push({
        id,
        key: `p${id}`,
        row: r,
        col: c,
        corners,
        cu,
        cv,
        startIndex: null,
        puzzle: { id: inst.recipeId, label: inst.label, rot: inst.rot, edges: inst.edges },
        tiles: { cells, counts, rects, habitable, coastal: (counts.sea || 0) > 0 },
        patches,
        terrain_ru: habitable ? 'пригодна' : 'малопригодна',
      })
    }
  }

  const startSlots = [
    { c: 2, r: 1 },
    { c: 5, r: 1 },
    { c: 3, r: 2 },
    { c: 1, r: 3 },
    { c: 6, r: 3 },
  ]
  const hab = () => provinces.filter((p) => p.tiles.habitable && !p.startIndex)
  startSlots.forEach((slot, i) => {
    let p = provinces.find((x) => x.col === slot.c && x.row === slot.r)
    if (!p || !p.tiles.habitable) {
      p = hab().sort((a, b) => {
        const da = Math.abs(a.col - slot.c) + Math.abs(a.row - slot.r)
        const db = Math.abs(b.col - slot.c) + Math.abs(b.row - slot.r)
        return da - db
      })[0]
    }
    if (p) p.startIndex = i + 1
  })

  const counts = Object.fromEntries(Object.keys(TERRAIN).map((k) => [k, 0]))
  provinces.forEach((p) => {
    Object.keys(counts).forEach((k) => {
      counts[k] += p.tiles.counts[k] || 0
    })
  })

  return {
    provinces,
    cols,
    rows,
    counts,
    meta: {
      mode: 'puzzle-16',
      seed,
      recipeCount: PUZZLE_RECIPES.length,
      recipeUses,
      habitable: provinces.filter((p) => p.tiles.habitable).length,
      waterComps: { pond: 0, lake: 0, sea: 0 },
      greenComps: { square: 0, park: 0, forest: 0 },
      rockComps: { ridge: 0, mountain: 0 },
      riversMade: 0,
    },
  }
}

function projectUV(u, v, mode, width, height, size, lonOffset = 0) {
  if (mode === 'globe') {
    const R = size * 0.42
    const lon = (u - 0.5) * Math.PI * 2 + lonOffset
    const lat = (0.5 - v) * Math.PI
    const x = Math.cos(lat) * Math.sin(lon)
    const y = Math.sin(lat)
    const z = Math.cos(lat) * Math.cos(lon)
    return { x: size / 2 + R * x, y: size / 2 - R * y, z }
  }
  return { x: 10 + u * (width - 20), y: 10 + v * (height - 20), z: 1 }
}

function projectShape(corners, cu, cv, mode, width, height, size, lonOffset) {
  const projected = corners.map((pt) => projectUV(pt.u, pt.v, mode, width, height, size, lonOffset))
  const zAvg = projected.reduce((s, q) => s + q.z, 0) / projected.length
  const mid = projectUV(cu, cv, mode, width, height, size, lonOffset)
  return {
    pointsAttr: projected.map((q) => `${q.x},${q.y}`).join(' '),
    cx: mid.x,
    cy: mid.y,
    visible: mode !== 'globe' || zAvg > -0.02,
    depth: zAvg,
  }
}

export function projectFlat(provinces, width = 960, height = 520) {
  return provinces.map((p) => {
    const shape = projectShape(p.corners, p.cu, p.cv, 'flat', width, height, 0, 0)
    const patches = p.patches.map((m) => ({
      ...m,
      ...projectShape(m.corners, m.cu, m.cv, 'flat', width, height, 0, 0),
    }))
    return { ...p, ...shape, patches }
  })
}

export function projectGlobe(provinces, size = 540, lonOffset = 0) {
  return provinces
    .map((p) => {
      const shape = projectShape(p.corners, p.cu, p.cv, 'globe', 0, 0, size, lonOffset)
      const patches = p.patches
        .map((m) => ({
          ...m,
          ...projectShape(m.corners, m.cu, m.cv, 'globe', 0, 0, size, lonOffset),
        }))
        .filter((m) => m.visible)
        .sort((a, b) => a.depth - b.depth)
      return { ...p, ...shape, patches }
    })
    .sort((a, b) => a.depth - b.depth)
}
