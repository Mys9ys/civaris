/**
 * Civaris — минимальный прототип генерации (после разбора чужого опыта)
 *
 * Пайплайн (Civ VII / Red Blob / indie GS):
 *  1) плиты — семена + random flood (не ромб от центра)
 *  2) суша / океан по типу плиты + шум берега
 *  3) горы на стыках плит (коллизия)
 *  4) влага от берега → лес
 *  5) реки downhill к воде
 *  6) провинции пока — прямоугольная нарезка поверх (идея: потом нарезать по клеткам)
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
  ridge: { key: 'ridge', ru: 'гряда', fill: '#7a756e', family: 'rock' },
  mountain: { key: 'mountain', ru: 'горы', fill: '#7a756e', family: 'rock' },
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

function neighbors4(x, y, W, H) {
  const out = []
  if (x > 0) out.push([x - 1, y])
  if (x < W - 1) out.push([x + 1, y])
  if (y > 0) out.push([x, y - 1])
  if (y < H - 1) out.push([x, y + 1])
  return out
}

/** Расстояние до ближайшего моря (грубый поиск) */
function seaDist(grid, x, y, W, H, maxR = 40) {
  for (let r = 1; r <= maxR; r++) {
    for (let dy = -r; dy <= r; dy++) {
      const adx = r - Math.abs(dy)
      for (const xx of [x + adx, x - adx]) {
        const ny = y + dy
        if (xx < 0 || ny < 0 || xx >= W || ny >= H) continue
        if (grid[ny][xx] === 'sea') return r
      }
    }
  }
  return maxR + 1
}

/** Проплешины равнин внутри гор/гряды → камень */
function sealRockInteriors(grid, W, H) {
  for (let pass = 0; pass < 4; pass++) {
    const fix = []
    for (let y = 0; y < H; y++) {
      for (let x = 0; x < W; x++) {
        if (grid[y][x] !== 'plain' && grid[y][x] !== 'beach' && !GREEN.has(grid[y][x])) continue
        const nbs = neighbors4(x, y, W, H)
        const rocks = nbs.filter(([nx, ny]) => ROCK.has(grid[ny][nx]))
        if (rocks.length >= Math.max(3, nbs.length - 1)) {
          fix.push([x, y, grid[rocks[0][1]][rocks[0][0]]])
        }
      }
    }
    for (const [x, y, t] of fix) grid[y][x] = t
  }
}

/** Длинные реки: сток по высоте, предпочтение к морю, минимум длины */
function carveLongRivers(grid, elev, W, H, seed) {
  let made = 0
  const rockEdges = []
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (!ROCK.has(grid[y][x])) continue
      if (neighbors4(x, y, W, H).some(([nx, ny]) => !ROCK.has(grid[ny][nx]) && grid[ny][nx] !== 'sea')) {
        rockEdges.push([x, y])
      }
    }
  }
  if (!rockEdges.length) return 0

  const tries = Math.min(18, rockEdges.length)
  for (let i = 0; i < tries; i++) {
    const idx = Math.floor(hash01(seed + 800 + i * 7) * rockEdges.length)
    let [x, y] = rockEdges[idx]
    // старт — сосед равнины у гряды
    const startNb = neighbors4(x, y, W, H).find(
      ([nx, ny]) => grid[ny][nx] === 'plain' || GREEN.has(grid[ny][nx]) || grid[ny][nx] === 'beach'
    )
    if (startNb) {
      x = startNb[0]
      y = startNb[1]
    }

    const path = []
    const seen = new Set()
    let reachedWater = false
    for (let step = 0; step < 220; step++) {
      const k = `${x},${y}`
      if (seen.has(k)) break
      seen.add(k)
      path.push([x, y])
      const t = grid[y][x]
      if ((WATER.has(t) && t !== 'river') || t === 'river') {
        reachedWater = true
        break
      }

      let best = null
      let bestScore = Infinity
      for (const [nx, ny] of neighbors4(x, y, W, H)) {
        if (seen.has(`${nx},${ny}`)) continue
        if (ROCK.has(grid[ny][nx])) continue
        // score: ниже = лучше; бонус к морю/реке
        let score = elev[ny][nx]
        score += hash01(seed + nx * 3 + ny * 11 + step) * 0.02
        const nt = grid[ny][nx]
        if (nt === 'sea' || nt === 'lake' || nt === 'pond') score -= 0.35
        if (nt === 'river') score -= 0.25
        // лёгкий уклон к ближайшему морю
        score += seaDist(grid, nx, ny, W, H, 30) * 0.004
        if (score < bestScore) {
          bestScore = score
          best = [nx, ny]
        }
      }
      if (!best) break
      x = best[0]
      y = best[1]
    }

    if (!reachedWater || path.length < 12) continue
    for (let p = 0; p < path.length; p++) {
      const [px, py] = path[p]
      const t = grid[py][px]
      if (WATER.has(t) && t !== 'river') break
      if (t === 'river') break
      if (!ROCK.has(t)) grid[py][px] = 'river'
    }
    made++
  }
  return made
}

function lerpUV(a, b, t) {
  return { u: lerp(a.u, b.u, t), v: lerp(a.v, b.v, t) }
}
function bilinear(corners, s, t) {
  const [tl, tr, br, bl] = corners
  return lerpUV(lerpUV(tl, tr, s), lerpUV(bl, br, s), t)
}

/**
 * Random-pick flood: заполняет карту от семян плит.
 * В очередь кладём соседей, берём СЛУЧАЙНЫЙ элемент — края живые, не ромбы.
 */
function buildPlates(W, H, plateCount, seed) {
  const plate = Array.from({ length: H }, () => Array(W).fill(-1))
  const queue = []
  const seeds = []

  for (let i = 0; i < plateCount; i++) {
    let x
    let y
    let tries = 0
    do {
      x = Math.floor(hash01(seed + i * 17 + tries) * W)
      y = Math.floor(hash01(seed + i * 31 + tries + 3) * H)
      tries++
    } while (tries < 40 && plate[y][x] !== -1)
    plate[y][x] = i
    seeds.push({ x, y, id: i })
    queue.push([x, y, i])
  }

  while (queue.length) {
    const idx = Math.floor(hash01(seed + queue.length * 13) * queue.length)
    const [x, y, id] = queue.splice(idx, 1)[0]
    const nbs = neighbors4(x, y, W, H)
    // слегка предпочитаем направление «движения» плиты
    const ang = hash01(seed + id * 9) * Math.PI * 2
    const prefer = { x: Math.cos(ang), y: Math.sin(ang) }
    nbs.sort((a, b) => {
      const da = (a[0] - x) * prefer.x + (a[1] - y) * prefer.y + hash01(seed + a[0] + a[1])
      const db = (b[0] - x) * prefer.x + (b[1] - y) * prefer.y + hash01(seed + b[0] + b[1])
      return db - da
    })
    for (const [nx, ny] of nbs) {
      if (plate[ny][nx] !== -1) continue
      // шанс пропустить — рваный край
      if (hash01(seed + nx * 19 + ny * 23 + id) < 0.08) continue
      plate[ny][nx] = id
      queue.push([nx, ny, id])
    }
  }

  // добить дырки
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (plate[y][x] !== -1) continue
      const nbs = neighbors4(x, y, W, H)
      const votes = {}
      for (const [nx, ny] of nbs) {
        const p = plate[ny][nx]
        if (p >= 0) votes[p] = (votes[p] || 0) + 1
      }
      let best = 0
      let bestN = -1
      for (const [k, v] of Object.entries(votes)) {
        if (v > bestN) {
          bestN = v
          best = +k
        }
      }
      plate[y][x] = best
    }
  }

  return { plate, seeds, plateCount }
}

function buildProtoTerrain(cols, rows, tileN, seed = 42) {
  const W = cols * tileN
  const H = rows * tileN
  const plateCount = 9
  const { plate } = buildPlates(W, H, plateCount, seed)

  // ~60–70% суши
  const continental = Array(plateCount).fill(false)
  let landPlates = 0
  const targetLand = Math.ceil(plateCount * 0.65)
  for (let i = 0; i < plateCount; i++) {
    if (landPlates < targetLand && hash01(seed + 100 + i) > 0.28) {
      continental[i] = true
      landPlates++
    }
  }
  if (landPlates < 5) {
    for (let i = 0; i < plateCount && landPlates < 5; i++) {
      if (!continental[i]) {
        continental[i] = true
        landPlates++
      }
    }
  }

  // вектор «движения» плиты — для коллизий
  const vel = Array.from({ length: plateCount }, (_, i) => {
    const a = hash01(seed + 200 + i) * Math.PI * 2
    return { x: Math.cos(a), y: Math.sin(a) }
  })

  const elev = Array.from({ length: H }, () => Array(W).fill(0))

  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const p = plate[y][x]
      const coastNoise = fbm(x / W * 5, y / H * 5, seed + 7, 4)
      let e = continental[p] ? 0.58 + coastNoise * 0.1 : 0.18 + coastNoise * 0.08
      e += (fbm(x / W * 12, y / H * 12, seed + 8, 3) - 0.5) * 0.06
      elev[y][x] = e
    }
  }

  // uplift на стыках (коллизия / субдукция упрощённо)
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const p = plate[y][x]
      for (const [nx, ny] of neighbors4(x, y, W, H)) {
        const q = plate[ny][nx]
        if (q === p) continue
        const dx = nx - x
        const dy = ny - y
        // сближаются?
        const approach = -(vel[p].x * dx + vel[p].y * dy) - (vel[q].x * -dx + vel[q].y * -dy)
        if (approach > 0.25) {
          if (continental[p] && continental[q]) elev[y][x] += 0.18
          else if (continental[p] || continental[q]) elev[y][x] += 0.1
          else elev[y][x] += 0.02
        } else if (approach < -0.25) {
          elev[y][x] -= 0.04
        }
      }
    }
  }

  // сгладить uplift
  for (let pass = 0; pass < 2; pass++) {
    const next = elev.map((row) => row.slice())
    for (let y = 1; y < H - 1; y++) {
      for (let x = 1; x < W - 1; x++) {
        let s = 0
        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) s += elev[y + dy][x + dx]
        next[y][x] = s / 9
      }
    }
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) elev[y][x] = next[y][x]
  }

  const seaLevel = 0.4
  const grid = Array.from({ length: H }, () => Array(W).fill('plain'))

  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const e = elev[y][x]
      if (e < seaLevel) grid[y][x] = 'sea'
      else if (e > 0.78) grid[y][x] = 'mountain'
      else if (e > 0.68) grid[y][x] = 'ridge'
      else grid[y][x] = 'plain'
    }
  }

  // --- лес: 3–4 отдельных массива, каждый ~в 3–4 раза меньше «ленты у берега» ---
  const COAST_BUFFER = 8
  const FOREST_COUNT = 4
  const FOREST_SIZE = [90, 160] // клеток на массив (класс парк/небольшой лес)

  // очистить любой лес у берега заранее — лес только точечной посадкой
  placeDiscreteForests(grid, elev, W, H, seed, {
    count: FOREST_COUNT,
    sizeMin: FOREST_SIZE[0],
    sizeMax: FOREST_SIZE[1],
    coastBuffer: COAST_BUFFER,
  })

  sealRockInteriors(grid, W, H)
  // горы/гряды: оставить 3–4 компактных массива
  trimRockMasses(grid, W, H, seed, 4, 35, 90)

  // пляж у моря (по равнине)
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (grid[y][x] !== 'plain') continue
      if (neighbors4(x, y, W, H).some(([nx, ny]) => grid[ny][nx] === 'sea')) {
        grid[y][x] = 'beach'
      }
    }
  }
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (grid[y][x] !== 'plain') continue
      if (!neighbors4(x, y, W, H).some(([nx, ny]) => grid[ny][nx] === 'beach')) continue
      if (neighbors4(x, y, W, H).some(([nx, ny]) => grid[ny][nx] === 'sea')) continue
      if (hash01(x * 11 + y * 3 + seed) > 0.45) grid[y][x] = 'beach'
    }
  }

  // озёра вглубь суши
  for (let y = 2; y < H - 2; y++) {
    for (let x = 2; x < W - 2; x++) {
      if (grid[y][x] !== 'plain') continue
      if (seaDist(grid, x, y, W, H) < COAST_BUFFER) continue
      if (elev[y][x] > seaLevel + 0.06) continue
      let lower = 0
      for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          if (!dx && !dy) continue
          if (elev[y + dy][x + dx] > elev[y][x] + 0.02) lower++
        }
      }
      if (lower >= 6 && hash01(x * 9 + y * 13 + seed) > 0.55) grid[y][x] = 'lake'
    }
  }

  let riversMade = carveLongRivers(grid, elev, W, H, seed)
  sealRockInteriors(grid, W, H)
  classifyWaterGreen(grid, W, H)

  return {
    grid,
    W,
    H,
    plate,
    elev,
    meta: {
      mode: 'proto-plates',
      plateCount,
      landPlates,
      riversMade,
      seed,
    },
  }
}

/** 3–4 лесных массива вглубь суши, компактные */
function placeDiscreteForests(grid, elev, W, H, seed, opts) {
  const count = opts.count
  const sizeMin = opts.sizeMin
  const sizeMax = opts.sizeMax
  const coastBuffer = opts.coastBuffer
  const placedCenters = []

  // убрать старый лес если был
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (GREEN.has(grid[y][x])) grid[y][x] = 'plain'
    }
  }

  for (let i = 0; i < count; i++) {
    let sx = -1
    let sy = -1
    for (let tryN = 0; tryN < 80; tryN++) {
      const x = 4 + Math.floor(hash01(seed + 500 + i * 17 + tryN) * (W - 8))
      const y = 4 + Math.floor(hash01(seed + 600 + i * 19 + tryN) * (H - 8))
      if (grid[y][x] !== 'plain') continue
      if (ROCK.has(grid[y][x])) continue
      if (seaDist(grid, x, y, W, H) <= coastBuffer) continue
      // не рядом с уже посаженным лесом
      let far = true
      for (const [cx, cy] of placedCenters) {
        if (Math.hypot(x - cx, y - cy) < 22) {
          far = false
          break
        }
      }
      if (!far) continue
      sx = x
      sy = y
      break
    }
    if (sx < 0) continue

    const target = sizeMin + Math.floor(hash01(seed + 700 + i) * (sizeMax - sizeMin + 1))
    const painted = paintOrganic(grid, W, H, sx, sy, 'forest', target, seed + 800 + i, {
      allow: (x, y) =>
        grid[y][x] === 'plain' &&
        seaDist(grid, x, y, W, H) > coastBuffer &&
        !ROCK.has(grid[y][x]),
    })
    if (painted >= sizeMin * 0.6) placedCenters.push([sx, sy])
    else {
      // откат мелкого
      // (оставить как есть — classify уберёт крошки)
    }
  }
}

function paintOrganic(grid, W, H, sx, sy, type, target, seed, opts = {}) {
  const allow = opts.allow || ((x, y) => grid[y][x] === 'plain')
  const R = Math.max(5, Math.ceil(Math.sqrt(target) * 1.6))
  const keep = new Set()
  for (let dy = -R; dy <= R; dy++) {
    for (let dx = -R; dx <= R; dx++) {
      const x = sx + dx
      const y = sy + dy
      if (x < 0 || y < 0 || x >= W || y >= H) continue
      if (!allow(x, y)) continue
      const dist = Math.sqrt(dx * dx + dy * dy) / (R + 0.01)
      const n = fbm(x / W * 7 + seed * 0.01, y / H * 7, seed, 4)
      if (n * 0.65 + (1 - dist) * 0.35 > 0.42) keep.add(`${x},${y}`)
    }
  }
  const painted = []
  const q = [[sx, sy]]
  const seen = new Set([`${sx},${sy}`])
  while (q.length && painted.length < target) {
    const pick = Math.floor(hash01(seed + painted.length * 5) * Math.min(q.length, 7))
    const [x, y] = q.splice(pick, 1)[0]
    if (!allow(x, y)) continue
    if (!keep.has(`${x},${y}`) && painted.length > 4) continue
    grid[y][x] = type
    painted.push([x, y])
    for (const n of neighbors4(x, y, W, H)) {
      const k = `${n[0]},${n[1]}`
      if (seen.has(k)) continue
      seen.add(k)
      q.push(n)
    }
  }
  return painted.length
}

/** Оставить до maxCount компактных горных массивов */
function trimRockMasses(grid, W, H, seed, maxCount, sizeMin, sizeMax) {
  const comps = [
    ...componentsOf(grid, W, H, new Set(['mountain'])),
    ...componentsOf(grid, W, H, new Set(['ridge'])),
  ].sort((a, b) => b.length - a.length)

  // всё в равнину, потом заново посадить 3–4 компактных
  for (const cells of comps) {
    for (const [x, y] of cells) grid[y][x] = 'plain'
  }

  const centers = comps.slice(0, Math.min(maxCount, comps.length)).map((c) => {
    const mid = c[Math.floor(c.length / 2)]
    return mid
  })
  // если мало — добавить случайные точки на суше с высоким elev не нужно — используем бывшие центры
  while (centers.length < Math.min(3, maxCount)) {
    const x = 5 + Math.floor(hash01(seed + 900 + centers.length) * (W - 10))
    const y = 5 + Math.floor(hash01(seed + 910 + centers.length) * (H - 10))
    if (grid[y][x] === 'sea') continue
    centers.push([x, y])
  }

  for (let i = 0; i < Math.min(maxCount, centers.length); i++) {
    const [sx, sy] = centers[i]
    if (grid[sy][sx] === 'sea') continue
    const target = sizeMin + Math.floor(hash01(seed + 920 + i) * (sizeMax - sizeMin + 1))
    const type = hash01(seed + 930 + i) > 0.45 ? 'mountain' : 'ridge'
    paintOrganic(grid, W, H, sx, sy, type, target, seed + 940 + i, {
      allow: (x, y) => grid[y][x] === 'plain' || GREEN.has(grid[y][x]),
    })
  }
  sealRockInteriors(grid, W, H)
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

function classifyWaterGreen(grid, W, H) {
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
  for (const cells of componentsOf(grid, W, H, new Set(['ridge']))) {
    if (cells.length < 5) for (const [x, y] of cells) grid[y][x] = 'plain'
  }
  for (const cells of componentsOf(grid, W, H, new Set(['mountain']))) {
    if (cells.length < 10) {
      for (const [x, y] of cells) grid[y][x] = cells.length >= 5 ? 'ridge' : 'plain'
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

function sliceProvince(grid, pc, pr, tileN) {
  const counts = Object.fromEntries(Object.keys(TERRAIN).map((k) => [k, 0]))
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
  const habitable = plain >= total * 0.12 && waterAccess >= 6

  return {
    cells,
    counts,
    rects: mergeProvinceRects(cells, tileN),
    habitable,
    coastal: (counts.sea || 0) > 0 && land > 0,
  }
}

/** @param {number} [seed] — смени seed, чтобы перегенерировать */
export function buildSmallProvinces(seed = 42) {
  const { cols, rows, tileSize: tileN } = MAP_SMALL
  const { grid, meta, plate } = buildProtoTerrain(cols, rows, tileN, seed)

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

      // доминирующая плита в провинции (для отладки)
      const plateVotes = {}
      for (let tj = 0; tj < tileN; tj++) {
        for (let ti = 0; ti < tileN; ti++) {
          const p = plate[r * tileN + tj][c * tileN + ti]
          plateVotes[p] = (plateVotes[p] || 0) + 1
        }
      }
      const plateId = +Object.entries(plateVotes).sort((a, b) => b[1] - a[1])[0][0]

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
        plateId,
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

  const waterComps = {
    pond: componentsOf(grid, cols * tileN, rows * tileN, new Set(['pond'])).length,
    lake: componentsOf(grid, cols * tileN, rows * tileN, new Set(['lake'])).length,
    sea: componentsOf(grid, cols * tileN, rows * tileN, new Set(['sea'])).length,
  }
  const greenComps = {
    square: componentsOf(grid, cols * tileN, rows * tileN, new Set(['square'])).length,
    park: componentsOf(grid, cols * tileN, rows * tileN, new Set(['park'])).length,
    forest: componentsOf(grid, cols * tileN, rows * tileN, new Set(['forest'])).length,
  }
  const rockComps = {
    ridge: componentsOf(grid, cols * tileN, rows * tileN, new Set(['ridge'])).length,
    mountain: componentsOf(grid, cols * tileN, rows * tileN, new Set(['mountain'])).length,
  }

  return {
    provinces,
    cols,
    rows,
    counts,
    meta: {
      ...meta,
      waterComps,
      greenComps,
      rockComps,
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
