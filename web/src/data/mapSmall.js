/**
 * Civaris — рельеф (малая 40×20×20)
 *
 * Картинка: земной fbm (высота+влажность) + рост монолитов.
 * Правила поверх (мягко, без «ромбов» и палок):
 *   леса/моря ≥2 провинции друг от друга
 *   пруд/сквер = 1 провинция; озеро/парк — можно несколько
 *   гряда = 2 провинции; гора ≥4
 *   река — линия 1 кл., может впадать в реку
 *   в лесу нет равнин
 * Пригодность: равнина≥20% + вода≥8; все 40.
 */

export const MAP_SMALL = {
  key: 'small',
  label: 'Малая',
  cols: 8,
  rows: 5,
  provinceCount: 40,
  startCount: 5,
  maxCitiesPerCountry: 8,
  tileSize: 20,
}

export const SPACING = { forest: 2, sea: 2 }

export const EARTH_THRESHOLDS = {
  sea: 0.39,
  mountain: 0.73,
  forestMoist: 0.45,
  forestElevMax: 0.7,
}

export const HABITABLE = {
  plainMinRatio: 0.12, // чуть мягче — иначе «все 40» выедают лес
  waterMin: 6,
}

function hash01(n) {
  const x = Math.sin(n * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}

function fade(t) {
  return t * t * (3 - 2 * t)
}

function lerp(a, b, t) {
  return a + (b - a) * t
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

function fbm(x, y, seed, octaves = 4) {
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

export const START_COLORS = ['#2f6b4a', '#8a5a2b', '#2f4f7a', '#7a3d5c', '#5a6b2f']

export const TERRAIN = {
  plain: { key: 'plain', ru: 'равнины / поля', fill: '#b7d39a', family: 'land' },
  beach: { key: 'beach', ru: 'пляж', fill: '#e6d5a8', family: 'land' },
  river: { key: 'river', ru: 'река', fill: '#4a8fb8', family: 'water' },
  pond: { key: 'pond', ru: 'пруд (6–10)', fill: '#3a7ea0', family: 'water' },
  lake: { key: 'lake', ru: 'озеро (11–25)', fill: '#3a7ea0', family: 'water' },
  sea: { key: 'sea', ru: 'море (26+)', fill: '#3a7ea0', family: 'water' },
  square: { key: 'square', ru: 'сквер (7–10)', fill: '#2f6b3a', family: 'green' },
  park: { key: 'park', ru: 'парк (11–35)', fill: '#2f6b3a', family: 'green' },
  forest: { key: 'forest', ru: 'лес (36+)', fill: '#2f6b3a', family: 'green' },
  ridge: { key: 'ridge', ru: 'гряда (5–15)', fill: '#7a756e', family: 'rock' },
  mountain: { key: 'mountain', ru: 'горы (16+)', fill: '#7a756e', family: 'rock' },
}

const WATER = new Set(['pond', 'lake', 'sea', 'river'])
const GREEN = new Set(['square', 'park', 'forest'])
const ROCK = new Set(['ridge', 'mountain'])

export function terrainFill(t) {
  return TERRAIN[t]?.fill || '#cfd6c4'
}

export function politicalFill(p) {
  if (p.startIndex) return START_COLORS[p.startIndex - 1]
  return '#cfd6c4'
}

function isWater(t) {
  return WATER.has(t)
}

function lerpUV(a, b, t) {
  return { u: lerp(a.u, b.u, t), v: lerp(a.v, b.v, t) }
}
function bilinear(corners, s, t) {
  const [tl, tr, br, bl] = corners
  return lerpUV(lerpUV(tl, tr, s), lerpUV(bl, br, s), t)
}

function neighbors4(x, y, W, H) {
  const out = []
  if (x > 0) out.push([x - 1, y])
  if (x < W - 1) out.push([x + 1, y])
  if (y > 0) out.push([x, y - 1])
  if (y < H - 1) out.push([x, y + 1])
  return out
}

function randInt(seed, a, b) {
  return a + Math.floor(hash01(seed) * (b - a + 1))
}

function cellProv(x, y, tileN) {
  return { pc: Math.floor(x / tileN), pr: Math.floor(y / tileN) }
}

function provKey(pc, pr) {
  return `${pc},${pr}`
}

function provincesOfCells(cells, tileN) {
  const set = new Set()
  for (const [x, y] of cells) set.add(provKey(cellProv(x, y, tileN).pc, cellProv(x, y, tileN).pr))
  return set
}

function chebyshevProv(a, b) {
  const [ac, ar] = a.split(',').map(Number)
  const [bc, br] = b.split(',').map(Number)
  return Math.max(Math.abs(ac - bc), Math.abs(ar - br))
}

function minProvDist(setA, setB) {
  let best = Infinity
  for (const a of setA) {
    for (const b of setB) best = Math.min(best, chebyshevProv(a, b))
  }
  return best
}

function componentsOf(grid, W, H, typeSet) {
  const seen = Array.from({ length: H }, () => Array(W).fill(false))
  const comps = []
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (seen[y][x] || !typeSet.has(grid[y][x])) continue
      const cells = []
      const q = [[x, y]]
      seen[y][x] = true
      while (q.length) {
        const [cx, cy] = q.shift()
        cells.push([cx, cy])
        for (const [nx, ny] of neighbors4(cx, cy, W, H)) {
          if (seen[ny][nx] || !typeSet.has(grid[ny][nx])) continue
          seen[ny][nx] = true
          q.push([nx, ny])
        }
      }
      comps.push(cells)
    }
  }
  return comps
}

function classifyBySize(grid, W, H) {
  for (const cells of componentsOf(grid, W, H, new Set(['pond', 'lake', 'sea']))) {
    const n = cells.length
    let t = 'sea'
    if (n < 6) t = 'plain'
    else if (n <= 10) t = 'pond'
    else if (n <= 25) t = 'lake'
    else t = 'sea'
    for (const [x, y] of cells) grid[y][x] = t
  }
  for (const cells of componentsOf(grid, W, H, new Set(['square', 'park', 'forest']))) {
    const n = cells.length
    let t = 'forest'
    if (n < 7) t = 'plain'
    else if (n <= 10) t = 'square'
    else if (n <= 35) t = 'park'
    else t = 'forest'
    for (const [x, y] of cells) grid[y][x] = t
  }
  // гряда и гора — раздельно (не сливать в одну компоненту)
  for (const cells of componentsOf(grid, W, H, new Set(['ridge']))) {
    const n = cells.length
    if (n < 5) for (const [x, y] of cells) grid[y][x] = 'plain'
    else for (const [x, y] of cells) grid[y][x] = 'ridge'
  }
  for (const cells of componentsOf(grid, W, H, new Set(['mountain']))) {
    const n = cells.length
    if (n < 16) {
      // слишком мелко для «большой горы» — убрать (гряды ставим отдельно)
      for (const [x, y] of cells) grid[y][x] = 'plain'
    } else for (const [x, y] of cells) grid[y][x] = 'mountain'
  }
}

/** Органичный блоб по шуму (не ромб от BFS) */
function paintBlob(grid, W, H, sx, sy, type, target, seed, opts = {}) {
  const onlyProv = opts.onlyProv || null
  const tileN = opts.tileN || 20
  const avoidTypes = opts.avoidTypes || new Set()
  const overwrite = !!opts.overwrite

  const canPaint = (x, y) => {
    if (onlyProv) {
      const p = cellProv(x, y, tileN)
      if (p.pc !== onlyProv.pc || p.pr !== onlyProv.pr) return false
    }
    const t = grid[y][x]
    if (t === type) return true
    if (t === 'plain') return true
    if (overwrite && !avoidTypes.has(t) && t !== 'river') return true
    return false
  }

  // радиус с запасом + отбор по fbm, затем связность от семени
  const R = Math.max(4, Math.ceil(Math.sqrt(target) * 1.8))
  const scored = []
  for (let dy = -R; dy <= R; dy++) {
    for (let dx = -R; dx <= R; dx++) {
      const x = sx + dx
      const y = sy + dy
      if (x < 0 || y < 0 || x >= W || y >= H) continue
      if (!canPaint(x, y)) continue
      const dist = Math.sqrt(dx * dx + dy * dy) / (R + 0.01)
      const n = fbm(x / W * 6 + seed * 0.01, y / H * 6, seed, 4)
      const score = n * 0.7 + (1 - dist) * 0.3 + hash01(seed + x * 13 + y) * 0.08
      scored.push({ x, y, score })
    }
  }
  scored.sort((a, b) => b.score - a.score)
  const keep = new Set()
  for (const c of scored) {
    if (keep.size >= target * 2) break
    keep.add(`${c.x},${c.y}`)
  }

  const painted = []
  const q = [[sx, sy]]
  const seen = new Set([`${sx},${sy}`])
  while (q.length && painted.length < target) {
    const pick = Math.floor(hash01(seed + painted.length * 3) * Math.min(q.length, 6))
    const [x, y] = q.splice(pick, 1)[0]
    const k = `${x},${y}`
    if (!keep.has(k) && painted.length > 3) {
      // редкие «усики» по шуму
      if (hash01(seed + x + y * 7) > 0.35) continue
    }
    if (!canPaint(x, y)) continue
    if (grid[y][x] !== type) {
      grid[y][x] = type
      painted.push([x, y])
    }
    const nbs = neighbors4(x, y, W, H).sort(
      (a, b) =>
        fbm(b[0] / W, b[1] / H, seed + 2) - fbm(a[0] / W, a[1] / H, seed + 2)
    )
    for (const n of nbs) {
      const nk = `${n[0]},${n[1]}`
      if (seen.has(nk)) continue
      seen.add(nk)
      if (keep.has(nk) || hash01(seed + n[0] * 9 + n[1]) > 0.55) q.push(n)
    }
  }
  return painted
}

function growFamily(grid, W, H, familySet, intoType, passes, chance = 0.55) {
  for (let p = 0; p < passes; p++) {
    const add = []
    for (let y = 0; y < H; y++) {
      for (let x = 0; x < W; x++) {
        if (grid[y][x] !== 'plain') continue
        const n = neighbors4(x, y, W, H).filter(([nx, ny]) => familySet.has(grid[ny][nx])).length
        const noise = fbm(x / W * 8, y / H * 8, 55 + p, 3)
        if (n >= 2 && noise > 0.35 && hash01(x * 17 + y * 31 + p * 101) < chance + n * 0.1) {
          add.push([x, y])
        }
      }
    }
    for (const [x, y] of add) grid[y][x] = intoType
  }
}

/** Органичный коридор между двумя точками (не прямая палка) */
function paintNoiseCorridor(grid, W, H, x0, y0, x1, y1, type, seed, width = 2) {
  const steps = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0), 8) * 2
  for (let t = 0; t <= steps; t++) {
    const u = t / steps
    const bx = lerp(x0, x1, u)
    const by = lerp(y0, y1, u)
    const nx = fbm(u * 4, seed * 0.01, seed, 3) - 0.5
    const ny = fbm(u * 4 + 2, seed * 0.02, seed + 1, 3) - 0.5
    const cx = Math.round(bx + nx * 6)
    const cy = Math.round(by + ny * 6)
    for (let dy = -width; dy <= width; dy++) {
      for (let dx = -width; dx <= width; dx++) {
        if (dx * dx + dy * dy > width * width + 1) continue
        const x = cx + dx
        const y = cy + dy
        if (x < 0 || y < 0 || x >= W || y >= H) continue
        const tt = grid[y][x]
        if (tt === 'plain' || GREEN.has(tt) || ROCK.has(tt)) grid[y][x] = type
      }
    }
  }
}

function clearCells(grid, cells, to = 'plain') {
  for (const [x, y] of cells) grid[y][x] = to
}

function existingProvSets(grid, W, H, typeSet, tileN) {
  return componentsOf(grid, W, H, typeSet).map((c) => provincesOfCells(c, tileN))
}

function farEnough(candidateProvs, existingSets, minDist) {
  for (const set of existingSets) {
    if (minProvDist(candidateProvs, set) < minDist) return false
  }
  return true
}

/** Внутри зелени не оставляем равнины */
function sealForestInteriors(grid, W, H) {
  for (let pass = 0; pass < 5; pass++) {
    const fix = []
    for (let y = 0; y < H; y++) {
      for (let x = 0; x < W; x++) {
        if (grid[y][x] !== 'plain' && grid[y][x] !== 'beach') continue
        const nbs = neighbors4(x, y, W, H)
        const greens = nbs.filter(([nx, ny]) => GREEN.has(grid[ny][nx]))
        if (greens.length >= Math.max(3, nbs.length - 1)) {
          fix.push([x, y, grid[greens[0][1]][greens[0][0]]])
        }
      }
    }
    for (const [x, y, t] of fix) grid[y][x] = t
  }
}

function sealTiny(grid, W, H) {
  for (let pass = 0; pass < 3; pass++) {
    const fix = []
    for (let y = 0; y < H; y++) {
      for (let x = 0; x < W; x++) {
        if (grid[y][x] !== 'plain' && grid[y][x] !== 'beach') continue
        const nbs = neighbors4(x, y, W, H)
        const waters = nbs.filter(([nx, ny]) => isWater(grid[ny][nx]) && grid[ny][nx] !== 'river')
        if (waters.length >= Math.max(3, nbs.length - 1)) {
          fix.push([x, y, grid[waters[0][1]][waters[0][0]]])
        }
      }
    }
    for (const [x, y, t] of fix) grid[y][x] = t
  }
}

function paintBeachesAroundSeas(grid, W, H) {
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (grid[y][x] === 'beach') grid[y][x] = 'plain'
    }
  }
  const add = []
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (grid[y][x] !== 'plain') continue
      if (!neighbors4(x, y, W, H).some(([nx, ny]) => grid[ny][nx] === 'sea')) continue
      const nearRock = neighbors4(x, y, W, H).some(([nx, ny]) => ROCK.has(grid[ny][nx]))
      if (nearRock && hash01(x * 5 + y) > 0.45) continue
      add.push([x, y])
    }
  }
  for (const [x, y] of add) grid[y][x] = 'beach'
}

/** Пруд/сквер — оставить только в доминирующей провинции */
function clipToSingleProvince(grid, W, H, type, tileN) {
  for (const cells of componentsOf(grid, W, H, new Set([type]))) {
    const byProv = new Map()
    for (const [x, y] of cells) {
      const k = provKey(cellProv(x, y, tileN).pc, cellProv(x, y, tileN).pr)
      if (!byProv.has(k)) byProv.set(k, [])
      byProv.get(k).push([x, y])
    }
    if (byProv.size <= 1) continue
    let best = null
    let bestN = -1
    for (const [k, list] of byProv) {
      if (list.length > bestN) {
        bestN = list.length
        best = k
      }
    }
    for (const [k, list] of byProv) {
      if (k === best) continue
      clearCells(grid, list, 'plain')
    }
  }
}

/** Гряда на ровно 2 соседние провинции */
function placeRidgePair(grid, W, H, cols, rows, tileN, seed) {
  const pc = Math.floor(hash01(seed) * cols)
  const pr = Math.floor(hash01(seed + 1) * rows)
  const dirs = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
  ]
  const dir = dirs[Math.floor(hash01(seed + 2) * dirs.length)]
  const pc2 = pc + dir[0]
  const pr2 = pr + dir[1]
  if (pc2 < 0 || pr2 < 0 || pc2 >= cols || pr2 >= rows) return false

  const sx = pc * tileN + 4 + Math.floor(hash01(seed + 3) * (tileN - 8))
  const sy = pr * tileN + 4 + Math.floor(hash01(seed + 4) * (tileN - 8))
  const sx2 = pc2 * tileN + 4 + Math.floor(hash01(seed + 5) * (tileN - 8))
  const sy2 = pr2 * tileN + 4 + Math.floor(hash01(seed + 6) * (tileN - 8))
  paintBlob(grid, W, H, sx, sy, 'ridge', randInt(seed, 8, 14), seed + 10, {
    onlyProv: { pc, pr },
    tileN,
    overwrite: true,
    avoidTypes: new Set(['sea', 'lake', 'pond', 'river']),
  })
  paintBlob(grid, W, H, sx2, sy2, 'ridge', randInt(seed + 1, 8, 14), seed + 20, {
    onlyProv: { pc: pc2, pr: pr2 },
    tileN,
    overwrite: true,
    avoidTypes: new Set(['sea', 'lake', 'pond', 'river']),
  })
  paintNoiseCorridor(grid, W, H, sx, sy, sx2, sy2, 'ridge', seed + 30, 1)
  return true
}

function placeMountainCluster(grid, W, H, cols, rows, tileN, seed, minProvs = 4) {
  const pc0 = 1 + Math.floor(hash01(seed) * (cols - 2))
  const pr0 = 1 + Math.floor(hash01(seed + 1) * (rows - 2))
  const cluster = [{ pc: pc0, pr: pr0 }]
  const used = new Set([provKey(pc0, pr0)])
  const dirs = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
  ]
  let guard = 0
  while (cluster.length < minProvs && guard++ < 24) {
    const base = cluster[Math.floor(hash01(seed + 50 + guard) * cluster.length)]
    const dir = dirs[Math.floor(hash01(seed + 60 + guard) * dirs.length)]
    const pc = base.pc + dir[0]
    const pr = base.pr + dir[1]
    if (pc < 0 || pr < 0 || pc >= cols || pr >= rows) continue
    const k = provKey(pc, pr)
    if (used.has(k)) continue
    used.add(k)
    cluster.push({ pc, pr })
  }
  if (cluster.length < minProvs) return false

  const centers = []
  for (let i = 0; i < cluster.length; i++) {
    const { pc, pr } = cluster[i]
    const sx = pc * tileN + 3 + Math.floor(hash01(seed + 100 + i) * (tileN - 6))
    const sy = pr * tileN + 3 + Math.floor(hash01(seed + 200 + i) * (tileN - 6))
    centers.push([sx, sy])
    paintBlob(grid, W, H, sx, sy, 'mountain', randInt(seed + 300 + i, 22, 40), seed + 400 + i, {
      onlyProv: { pc, pr },
      tileN,
      overwrite: true,
      avoidTypes: new Set(['sea', 'lake', 'pond', 'river']),
    })
  }
  for (let i = 1; i < centers.length; i++) {
    paintNoiseCorridor(
      grid,
      W,
      H,
      centers[0][0],
      centers[0][1],
      centers[i][0],
      centers[i][1],
      'mountain',
      seed + 500 + i,
      2
    )
  }
  return true
}

function trimRidgesToTwoProvinces(grid, W, H, tileN) {
  for (const cells of componentsOf(grid, W, H, new Set(['ridge']))) {
    const byProv = new Map()
    for (const [x, y] of cells) {
      const k = provKey(cellProv(x, y, tileN).pc, cellProv(x, y, tileN).pr)
      if (!byProv.has(k)) byProv.set(k, [])
      byProv.get(k).push([x, y])
    }
    if (byProv.size === 2) continue
    if (byProv.size < 2) {
      clearCells(grid, cells, 'plain') // одиночные гряды без пары — убрать
      continue
    }
    const ranked = [...byProv.entries()].sort((a, b) => b[1].length - a[1].length)
    const keep = new Set(ranked.slice(0, 2).map(([k]) => k))
    for (const [k, list] of byProv) {
      if (!keep.has(k)) clearCells(grid, list, 'plain')
    }
  }
}

function dropSmallMountains(grid, W, H, tileN, minProvs = 4) {
  for (const cells of componentsOf(grid, W, H, new Set(['mountain']))) {
    if (provincesOfCells(cells, tileN).size < minProvs) clearCells(grid, cells, 'plain')
  }
}

/** Река — линия 1 клетки; может впадать в воду или в другую реку */
function carveRiverFrom(grid, W, H, start, seed) {
  const q = [{ path: [start] }]
  const seen = new Set([`${start[0]},${start[1]}`])
  let found = null
  while (q.length && !found) {
    const cur = q.shift()
    if (cur.path.length > 120) continue
    const last = cur.path[cur.path.length - 1]
    const nbs = neighbors4(last[0], last[1], W, H).sort(
      (a, b) => hash01(seed + a[0] * 3 + a[1] * 11) - hash01(seed + b[0] * 3 + b[1] * 11)
    )
    for (const [nx, ny] of nbs) {
      const k = `${nx},${ny}`
      if (seen.has(k)) continue
      const t = grid[ny][nx]
      if ((isWater(t) && t !== 'river') || t === 'river') {
        found = cur.path
        break
      }
      if (ROCK.has(t)) continue
      seen.add(k)
      q.push({ path: [...cur.path, [nx, ny]] })
    }
  }
  if (!found || found.length < 3) return false
  for (let i = 1; i < found.length; i++) {
    const [x, y] = found[i]
    const t = grid[y][x]
    if (t === 'river' || (isWater(t) && t !== 'river')) break
    grid[y][x] = 'river'
  }
  return true
}

function carveRiversFromMountains(grid, W, H) {
  let made = 0
  const ridges = componentsOf(grid, W, H, new Set(['ridge']))
  const bigs = componentsOf(grid, W, H, new Set(['mountain']))

  ridges.forEach((cells, i) => {
    const edge = cells.find(([x, y]) =>
      neighbors4(x, y, W, H).some(([nx, ny]) => !ROCK.has(grid[ny][nx]))
    )
    if (edge && carveRiverFrom(grid, W, H, edge, 1000 + i)) made++
  })

  bigs.forEach((cells, i) => {
    const edges = cells.filter(([x, y]) =>
      neighbors4(x, y, W, H).some(([nx, ny]) => !ROCK.has(grid[ny][nx]))
    )
    if (!edges.length) return
    // 2+ рек: главная + приток (ветвление в одну сеть)
    const a = edges[Math.floor(hash01(2000 + i) * edges.length)]
    const b = edges[Math.floor(hash01(2100 + i) * edges.length)]
    const c = edges[Math.floor(hash01(2150 + i) * edges.length)]
    if (carveRiverFrom(grid, W, H, a, 2200 + i)) made++
    if (a !== b && carveRiverFrom(grid, W, H, b, 2300 + i)) made++
    if (c !== a && c !== b && carveRiverFrom(grid, W, H, c, 2400 + i)) made++
  })

  return made
}

function placeSpacedFeature(grid, W, H, cols, rows, tileN, type, sizeRange, minProvDist, tries, seed0) {
  const existing = existingProvSets(grid, W, H, new Set([type]), tileN)
  let placed = 0
  for (let i = 0; i < tries; i++) {
    const pc = Math.floor(hash01(seed0 + i * 3) * cols)
    const pr = Math.floor(hash01(seed0 + i * 3 + 1) * rows)
    const sx = pc * tileN + 3 + Math.floor(hash01(seed0 + i * 5) * (tileN - 6))
    const sy = pr * tileN + 3 + Math.floor(hash01(seed0 + i * 5 + 1) * (tileN - 6))
    if (grid[sy][sx] !== 'plain') continue
    const size = randInt(seed0 + i, sizeRange[0], sizeRange[1])
    // оценка провинций для круга ~size
    const approx = new Set([provKey(pc, pr)])
    if (!farEnough(approx, existing, minProvDist)) continue
    const painted = paintBlob(grid, W, H, sx, sy, type, size, seed0 + 100 + i, { tileN })
    if (painted.length < sizeRange[0]) {
      clearCells(grid, painted)
      continue
    }
    const provs = provincesOfCells(painted, tileN)
    if (!farEnough(provs, existing, minProvDist)) {
      clearCells(grid, painted)
      continue
    }
    existing.push(provs)
    placed++
  }
  return placed
}

function buildWorldTerrain(cols, rows, tileN) {
  const W = cols * tileN
  const H = rows * tileN
  const T = EARTH_THRESHOLDS
  const elev = Array.from({ length: H }, () => Array(W).fill(0))
  const moist = Array.from({ length: H }, () => Array(W).fill(0))
  const grid = Array.from({ length: H }, () => Array(W).fill('plain'))

  // —— 1. земной скелет (как на «хорошем» скрине) ——
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const nx = x / W
      const ny = y / H
      const base = fbm(nx * 2.2, ny * 2.2, 11, 4)
      const detail = fbm(nx * 6, ny * 6, 22, 3) * 0.22
      const chain = Math.pow(Math.abs(fbm(nx * 1.6 + 3, ny * 1.6, 33, 3) - 0.5) * 2, 1.4)
      elev[y][x] = Math.min(1, base * 0.78 + detail + chain * 0.28)
      moist[y][x] = fbm(nx * 3.2 + 8, ny * 3.2, 44, 4)
    }
  }

  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const e = elev[y][x]
      const m = moist[y][x]
      if (e < T.sea) grid[y][x] = 'sea'
      else if (e > T.mountain) grid[y][x] = 'mountain'
      else if (m > T.forestMoist && e < T.forestElevMax) grid[y][x] = 'forest'
      else grid[y][x] = 'plain'
    }
  }

  growFamily(grid, W, H, new Set(['sea', 'lake', 'pond']), 'sea', 3, 0.5)
  growFamily(grid, W, H, GREEN, 'forest', 4, 0.55)
  growFamily(grid, W, H, ROCK, 'mountain', 2, 0.48)
  sealForestInteriors(grid, W, H)

  // впадины → озёра
  for (let y = 2; y < H - 2; y++) {
    for (let x = 2; x < W - 2; x++) {
      if (grid[y][x] !== 'plain') continue
      const e = elev[y][x]
      let lower = 0
      for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          if (!dx && !dy) continue
          if (elev[y + dy][x + dx] > e + 0.015) lower++
        }
      }
      if (lower >= 6 && moist[y][x] > 0.4) grid[y][x] = 'lake'
    }
  }
  growFamily(grid, W, H, new Set(['lake', 'pond']), 'lake', 2, 0.45)

  // мелкие пруды/скверы/парки поверх равнин
  for (let i = 0; i < 20; i++) {
    const pc = Math.floor(hash01(700 + i) * cols)
    const pr = Math.floor(hash01(710 + i) * rows)
    const sx = pc * tileN + 3 + Math.floor(hash01(720 + i) * (tileN - 6))
    const sy = pr * tileN + 3 + Math.floor(hash01(730 + i) * (tileN - 6))
    if (grid[sy][sx] !== 'plain') continue
    paintBlob(grid, W, H, sx, sy, 'pond', randInt(740 + i, 6, 10), 750 + i, {
      onlyProv: { pc, pr },
      tileN,
    })
  }
  for (let i = 0; i < 12; i++) {
    const sx = 3 + Math.floor(hash01(800 + i) * (W - 6))
    const sy = 3 + Math.floor(hash01(810 + i) * (H - 6))
    if (grid[sy][sx] !== 'plain') continue
    paintBlob(grid, W, H, sx, sy, 'park', randInt(820 + i, 14, 32), 830 + i, { tileN })
  }
  for (let i = 0; i < 14; i++) {
    const pc = Math.floor(hash01(900 + i) * cols)
    const pr = Math.floor(hash01(910 + i) * rows)
    const sx = pc * tileN + 3 + Math.floor(hash01(920 + i) * (tileN - 6))
    const sy = pr * tileN + 3 + Math.floor(hash01(930 + i) * (tileN - 6))
    if (grid[sy][sx] !== 'plain') continue
    paintBlob(grid, W, H, sx, sy, 'square', randInt(940 + i, 7, 10), 950 + i, {
      onlyProv: { pc, pr },
      tileN,
    })
  }

  sealTiny(grid, W, H)
  classifyBySize(grid, W, H)

  // —— 2. правила: сначала добрать горы/гряды, потом spacing (и больше не сливать леса) ——
  clipToSingleProvince(grid, W, H, 'pond', tileN)
  clipToSingleProvince(grid, W, H, 'square', tileN)

  let mountains = componentsOf(grid, W, H, new Set(['mountain']))
  if (mountains.filter((c) => provincesOfCells(c, tileN).size >= 4).length < 1) {
    placeMountainCluster(grid, W, H, cols, rows, tileN, 300, 4)
    placeMountainCluster(grid, W, H, cols, rows, tileN, 360, 4)
  }
  for (const cells of componentsOf(grid, W, H, new Set(['mountain']))) {
    let provs = provincesOfCells(cells, tileN)
    let guard = 0
    while (provs.size < 4 && guard++ < 8) {
      const [x0, y0] = cells[Math.floor(hash01(5700 + guard) * cells.length)]
      const p = cellProv(x0, y0, tileN)
      const dirs = [
        [1, 0],
        [-1, 0],
        [0, 1],
        [0, -1],
      ]
      const dir = dirs[guard % 4]
      const pc2 = Math.min(cols - 1, Math.max(0, p.pc + dir[0]))
      const pr2 = Math.min(rows - 1, Math.max(0, p.pr + dir[1]))
      if (provs.has(provKey(pc2, pr2))) continue
      const sx = pc2 * tileN + 4 + Math.floor(hash01(5800 + guard) * (tileN - 8))
      const sy = pr2 * tileN + 4 + Math.floor(hash01(5801 + guard) * (tileN - 8))
      paintBlob(grid, W, H, sx, sy, 'mountain', 32, 5500 + guard, {
        onlyProv: { pc: pc2, pr: pr2 },
        tileN,
        overwrite: true,
        avoidTypes: new Set(['sea', 'lake', 'pond', 'river']),
      })
      paintNoiseCorridor(grid, W, H, x0, y0, sx, sy, 'mountain', 5600 + guard, 2)
      const big = componentsOf(grid, W, H, new Set(['mountain'])).sort((a, b) => b.length - a.length)[0]
      cells.length = 0
      cells.push(...(big || []))
      provs = provincesOfCells(cells, tileN)
    }
  }

  if (componentsOf(grid, W, H, new Set(['ridge'])).length < 3) {
    for (let i = 0; i < 12 && componentsOf(grid, W, H, new Set(['ridge'])).length < 4; i++) {
      placeRidgePair(grid, W, H, cols, rows, tileN, 400 + i * 19)
    }
  }
  trimRidgesToTwoProvinces(grid, W, H, tileN)
  // не дропаем горы слепо — ещё раз дотянуть, потом drop
  for (let pass = 0; pass < 2; pass++) {
    for (const cells of componentsOf(grid, W, H, new Set(['mountain']))) {
      if (provincesOfCells(cells, tileN).size >= 4) continue
      const [x0, y0] = cells[0]
      const p = cellProv(x0, y0, tileN)
      for (const [dx, dy] of [
        [1, 0],
        [0, 1],
        [-1, 0],
        [0, -1],
      ]) {
        const pc2 = p.pc + dx
        const pr2 = p.pr + dy
        if (pc2 < 0 || pr2 < 0 || pc2 >= cols || pr2 >= rows) continue
        const sx = pc2 * tileN + Math.floor(tileN / 2)
        const sy = pr2 * tileN + Math.floor(tileN / 2)
        paintBlob(grid, W, H, sx, sy, 'mountain', 36, 6000 + pass * 10 + pc2, {
          onlyProv: { pc: pc2, pr: pr2 },
          tileN,
          overwrite: true,
          avoidTypes: new Set(['sea', 'lake', 'pond', 'river']),
        })
        paintNoiseCorridor(grid, W, H, x0, y0, sx, sy, 'mountain', 6100 + pass, 2)
      }
    }
  }
  dropSmallMountains(grid, W, H, tileN, 4)

  // нарастить оставшиеся леса/моря ПЕРЕД spacing
  growFamily(grid, W, H, GREEN, 'forest', 4, 0.58)
  growFamily(grid, W, H, new Set(['sea']), 'sea', 3, 0.52)
  sealForestInteriors(grid, W, H)
  classifyBySize(grid, W, H)

  enforceSpacing(grid, W, H, tileN, 'forest', SPACING.forest)
  enforceSpacing(grid, W, H, tileN, 'sea', SPACING.sea)

  // добрать крупные массивы вдалеке (картинка как на 2-м скрине, не россыпь крошек)
  placeSpacedFeature(grid, W, H, cols, rows, tileN, 'sea', [90, 160], SPACING.sea, 18, 1100)
  placeSpacedFeature(grid, W, H, cols, rows, tileN, 'forest', [100, 180], SPACING.forest, 18, 1200)

  enforceSpacing(grid, W, H, tileN, 'forest', SPACING.forest)
  enforceSpacing(grid, W, H, tileN, 'sea', SPACING.sea)
  // набухание оставшихся массивов (зазор в 2 провинции не перекроется)
  growFamily(grid, W, H, new Set(['forest']), 'forest', 8, 0.62)
  growFamily(grid, W, H, new Set(['sea']), 'sea', 7, 0.58)
  sealForestInteriors(grid, W, H)
  classifyBySize(grid, W, H)
  enforceSpacing(grid, W, H, tileN, 'forest', SPACING.forest)
  enforceSpacing(grid, W, H, tileN, 'sea', SPACING.sea)
  growFamily(grid, W, H, new Set(['forest']), 'forest', 4, 0.55)
  growFamily(grid, W, H, new Set(['sea']), 'sea', 4, 0.52)
  sealForestInteriors(grid, W, H)

  trimRidgesToTwoProvinces(grid, W, H, tileN)
  dropSmallMountains(grid, W, H, tileN, 4)
  clipToSingleProvince(grid, W, H, 'pond', tileN)
  clipToSingleProvince(grid, W, H, 'square', tileN)

  paintBeachesAroundSeas(grid, W, H)
  const riversMade = carveRiversFromMountains(grid, W, H)
  sealTiny(grid, W, H)
  sealForestInteriors(grid, W, H)
  classifyBySize(grid, W, H)
  clipToSingleProvince(grid, W, H, 'pond', tileN)
  clipToSingleProvince(grid, W, H, 'square', tileN)
  // финальный spacing без повторного вырезания после сильного роста
  enforceSpacing(grid, W, H, tileN, 'forest', SPACING.forest)
  enforceSpacing(grid, W, H, tileN, 'sea', SPACING.sea)

  const waterComps = {
    pond: componentsOf(grid, W, H, new Set(['pond'])).length,
    lake: componentsOf(grid, W, H, new Set(['lake'])).length,
    sea: componentsOf(grid, W, H, new Set(['sea'])).length,
  }
  const greenComps = {
    square: componentsOf(grid, W, H, new Set(['square'])).length,
    park: componentsOf(grid, W, H, new Set(['park'])).length,
    forest: componentsOf(grid, W, H, new Set(['forest'])).length,
  }
  const rockComps = {
    ridge: componentsOf(grid, W, H, new Set(['ridge'])).length,
    mountain: componentsOf(grid, W, H, new Set(['mountain'])).length,
  }

  return {
    grid,
    W,
    H,
    meta: { riversMade, waterComps, greenComps, rockComps, mode: 'earth+rules' },
  }
}

function enforceSpacing(grid, W, H, tileN, type, minDist) {
  const comps = componentsOf(grid, W, H, new Set([type])).sort((a, b) => b.length - a.length)
  const kept = []
  for (const cells of comps) {
    const provs = provincesOfCells(cells, tileN)
    if (farEnough(
      provs,
      kept.map((c) => provincesOfCells(c, tileN)),
      minDist
    )) {
      kept.push(cells)
    } else {
      clearCells(grid, cells, 'plain')
    }
  }
}

export function mergeProvinceRects(cells, tileN) {
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

function provinceCellStats(grid, pc, pr, tileN) {
  const counts = Object.fromEntries(Object.keys(TERRAIN).map((k) => [k, 0]))
  for (let tj = 0; tj < tileN; tj++) {
    for (let ti = 0; ti < tileN; ti++) {
      const t = grid[pr * tileN + tj][pc * tileN + ti]
      counts[t] = (counts[t] || 0) + 1
    }
  }
  const plain = (counts.plain || 0) + (counts.beach || 0)
  const waterAccess =
    (counts.sea || 0) +
    (counts.lake || 0) +
    (counts.pond || 0) +
    (counts.river || 0) +
    (counts.beach || 0)
  const total = tileN * tileN
  return {
    counts,
    plain,
    waterAccess,
    habitable: plain >= total * HABITABLE.plainMinRatio && waterAccess >= HABITABLE.waterMin,
  }
}

function setProvCell(grid, pc, pr, tileN, ti, tj, type) {
  grid[pr * tileN + tj][pc * tileN + ti] = type
}

function ensureAllProvincesHabitable(grid, cols, rows, tileN) {
  const W = cols * tileN
  const H = rows * tileN
  const needPlain = Math.ceil(tileN * tileN * HABITABLE.plainMinRatio)
  const needWater = HABITABLE.waterMin

  function score(ti, tj, pc, pr, seed) {
    return fbm((pc * tileN + ti) / W, (pr * tileN + tj) / H, 70 + seed, 3)
  }

  for (let pr = 0; pr < rows; pr++) {
    for (let pc = 0; pc < cols; pc++) {
      for (let guard = 0; guard < 6; guard++) {
        const st = provinceCellStats(grid, pc, pr, tileN)
        if (st.habitable) break

        if (st.plain < needPlain) {
          const ranked = []
          for (let tj = 0; tj < tileN; tj++) {
            for (let ti = 0; ti < tileN; ti++) {
              const t = grid[pr * tileN + tj][pc * tileN + ti]
              if (t === 'plain' || t === 'beach' || t === 'river') continue
              // поляну из леса — только с края (не дырявить массив)
              if (GREEN.has(t)) {
                const edge = neighbors4(ti, tj, tileN, tileN).some(([nx, ny]) => {
                  if (nx < 0 || ny < 0 || nx >= tileN || ny >= tileN) return true
                  const nt = grid[pr * tileN + ny][pc * tileN + nx]
                  return nt === 'plain' || nt === 'beach' || isWater(nt)
                })
                if (!edge) continue
              }
              ranked.push({ ti, tj, t, s: score(ti, tj, pc, pr, 3) })
            }
          }
          ranked.sort((a, b) => b.s - a.s)
          let left = needPlain - st.plain + 4
          let drained = 0
          for (const c of ranked) {
            if (left <= 0) break
            if (isWater(c.t) && st.waterAccess - drained <= needWater + 6) continue
            if (isWater(c.t)) drained++
            setProvCell(grid, pc, pr, tileN, c.ti, c.tj, 'plain')
            left--
          }
        }

        const st2 = provinceCellStats(grid, pc, pr, tileN)
        if (st2.waterAccess < needWater) {
          const ranked = []
          for (let tj = 0; tj < tileN; tj++) {
            for (let ti = 0; ti < tileN; ti++) {
              const t = grid[pr * tileN + tj][pc * tileN + ti]
              if (t !== 'plain' && !ROCK.has(t)) continue
              ranked.push({ ti, tj, s: score(ti, tj, pc, pr, 9) })
            }
          }
          ranked.sort((a, b) => b.s - a.s)
          let left = needWater - st2.waterAccess + 2
          for (const c of ranked) {
            if (left <= 0) break
            setProvCell(grid, pc, pr, tileN, c.ti, c.tj, 'pond')
            left--
          }
        }
      }
    }
  }

  clipToSingleProvince(grid, W, H, 'pond', tileN)
  sealForestInteriors(grid, W, H)
  classifyBySize(grid, W, H)
  paintBeachesAroundSeas(grid, W, H)
  clipToSingleProvince(grid, W, H, 'pond', tileN)
  clipToSingleProvince(grid, W, H, 'square', tileN)

  // финальный дожим
  for (let pr = 0; pr < rows; pr++) {
    for (let pc = 0; pc < cols; pc++) {
      const st = provinceCellStats(grid, pc, pr, tileN)
      if (st.habitable) continue
      if (st.plain < needPlain) {
        let left = needPlain - st.plain
        for (let tj = 0; tj < tileN && left > 0; tj++) {
          for (let ti = 0; ti < tileN && left > 0; ti++) {
            const t = grid[pr * tileN + tj][pc * tileN + ti]
            if (GREEN.has(t) || ROCK.has(t) || t === 'sea' || t === 'lake') {
              setProvCell(grid, pc, pr, tileN, ti, tj, 'plain')
              left--
            }
          }
        }
      }
      const st2 = provinceCellStats(grid, pc, pr, tileN)
      if (st2.waterAccess < needWater) {
        let left = needWater - st2.waterAccess
        for (let tj = 0; tj < tileN && left > 0; tj++) {
          for (let ti = 0; ti < tileN && left > 0; ti++) {
            const t = grid[pr * tileN + tj][pc * tileN + ti]
            if (t === 'plain' || ROCK.has(t)) {
              setProvCell(grid, pc, pr, tileN, ti, tj, 'pond')
              left--
            }
          }
        }
      }
    }
  }
  clipToSingleProvince(grid, W, H, 'pond', tileN)
  sealForestInteriors(grid, W, H)

  // ещё раз дожим пригодности после seal
  for (let pr = 0; pr < rows; pr++) {
    for (let pc = 0; pc < cols; pc++) {
      const st = provinceCellStats(grid, pc, pr, tileN)
      if (st.habitable) continue
      if (st.plain < needPlain) {
        let left = needPlain - st.plain + 2
        for (let tj = 0; tj < tileN && left > 0; tj++) {
          for (let ti = 0; ti < tileN && left > 0; ti++) {
            const t = grid[pr * tileN + tj][pc * tileN + ti]
            if (GREEN.has(t) || ROCK.has(t) || t === 'sea' || t === 'lake') {
              setProvCell(grid, pc, pr, tileN, ti, tj, 'plain')
              left--
            }
          }
        }
      }
      const st2 = provinceCellStats(grid, pc, pr, tileN)
      if (st2.waterAccess < needWater) {
        let left = needWater - st2.waterAccess + 2
        for (let tj = 0; tj < tileN && left > 0; tj++) {
          for (let ti = 0; ti < tileN && left > 0; ti++) {
            const t = grid[pr * tileN + tj][pc * tileN + ti]
            if (t === 'plain' || ROCK.has(t)) {
              setProvCell(grid, pc, pr, tileN, ti, tj, 'pond')
              left--
            }
          }
        }
      }
    }
  }
  clipToSingleProvince(grid, W, H, 'pond', tileN)
}

function sliceProvince(grid, pc, pr, tileN) {
  const keys = Object.keys(TERRAIN)
  const counts = Object.fromEntries(keys.map((k) => [k, 0]))
  const cells = []
  for (let tj = 0; tj < tileN; tj++) {
    const row = []
    for (let ti = 0; ti < tileN; ti++) {
      const t = grid[pr * tileN + tj][pc * tileN + ti]
      row.push(t)
      counts[t] = (counts[t] || 0) + 1
    }
    cells.push(row)
  }

  const plain = (counts.plain || 0) + (counts.beach || 0)
  const waterAccess =
    (counts.sea || 0) +
    (counts.lake || 0) +
    (counts.pond || 0) +
    (counts.river || 0) +
    (counts.beach || 0)
  const land =
    plain +
    (counts.square || 0) +
    (counts.park || 0) +
    (counts.forest || 0) +
    (counts.ridge || 0) +
    (counts.mountain || 0)

  const total = tileN * tileN
  const habitable =
    plain >= total * HABITABLE.plainMinRatio && waterAccess >= HABITABLE.waterMin

  return {
    cells,
    counts,
    rects: mergeProvinceRects(cells, tileN),
    habitable,
    coastal: (counts.sea || 0) > 0 && land > 0,
  }
}

export function buildSmallProvinces() {
  const { cols, rows, tileSize: tileN } = MAP_SMALL
  const { grid, meta } = buildWorldTerrain(cols, rows, tileN)
  ensureAllProvincesHabitable(grid, cols, rows, tileN)

  const verts = []
  for (let r = 0; r <= rows; r++) {
    const row = []
    for (let c = 0; c <= cols; c++) {
      row.push({ u: c / cols, v: r / rows, c, r })
    }
    verts.push(row)
  }

  const provinces = []
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const id = r * cols + c + 1
      const corners = [verts[r][c], verts[r][c + 1], verts[r + 1][c + 1], verts[r + 1][c]]
      const cu = corners.reduce((s, p) => s + p.u, 0) / 4
      const cv = corners.reduce((s, p) => s + p.v, 0) / 4
      const tiles = sliceProvince(grid, c, r, tileN)

      const patches = tiles.rects.map((rect, i) => {
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
        tiles,
        patches,
        terrain_ru: tiles.habitable ? 'пригодна' : 'малопригодна',
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
      ...meta,
      habitable: provinces.filter((p) => p.tiles.habitable).length,
    },
  }
}

function projectUV(u, v, mode, width, height, size, lonOffset = 0) {
  if (mode === 'globe') {
    const R = size * 0.42
    const cx0 = size / 2
    const cy0 = size / 2
    const lon = (u - 0.5) * Math.PI * 2 + lonOffset
    const lat = (0.5 - v) * Math.PI
    const x = Math.cos(lat) * Math.sin(lon)
    const y = Math.sin(lat)
    const z = Math.cos(lat) * Math.cos(lon)
    return { x: cx0 + R * x, y: cy0 - R * y, z }
  }
  const padX = 10
  const padY = 10
  return { x: padX + u * (width - padX * 2), y: padY + v * (height - padY * 2), z: 1 }
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
