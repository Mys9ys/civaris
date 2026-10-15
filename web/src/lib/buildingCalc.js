/**
 * Калькулятор сруба фазы 0.
 * Модуль: бревно 100×15×15 см. Рост куба: 60 см → потолок ~100–105 см (7 рядов).
 */

export const CUBE_HEIGHT_CM = 60

export const BUILD_DEFAULTS = {
  log_len_cm: 100,
  log_h_cm: 15,
  wall_t_cm: 15,
  stone_w_cm: 20,
  stone_l_cm: 10,
  board_w_cm: 10,
  board_l_cm: 100,
  roof_pitch: 1.15,
  clay_kg_per_m2: 25, // обмазка с одной стороны; стены ×2
  frames_boards_per_opening: 4,
  /** мин труда на 1 бревно в стене (черновик) */
  labor_min_per_log: 8,
  labor_workers: 2,
}

/** SKU-ключи сырья сруба (канон каталога). */
export const BUILD_MAT = {
  log: { sku_key: 'brevno', name_ru: 'Бревно' },
  stone: { sku_key: 'kamen_dlya_pola', name_ru: 'Камень для пола' },
  board: { sku_key: 'doska', name_ru: 'Доска' },
  clay: { sku_key: 'glina_1_kg', name_ru: 'Глина 1 кг' },
  curtain: { sku_key: 'shtory', name_ru: 'Шторы' },
}

function ceilPos(n) {
  if (!(n > 0)) return 0
  return Math.ceil(n)
}

/**
 * @param {object} p
 * @param {number} p.width_cm  внешняя ширина
 * @param {number} p.length_cm внешняя длина (глубина)
 * @param {number} p.height_cm высота стен до низа крыши
 * @param {number} [p.partitions] перегородки (ратуша: 1)
 * @param {number} [p.windows]
 * @param {number} [p.doors] вход + внутренняя
 * @param {object} [p.opts] переопределение BUILD_DEFAULTS
 */
export function calcBuilding(p) {
  const o = { ...BUILD_DEFAULTS, ...(p.opts || {}) }
  const W = Number(p.width_cm)
  const D = Number(p.length_cm)
  const H = Number(p.height_cm)
  const partitions = Math.max(0, Math.floor(Number(p.partitions ?? 1)))
  const windows = Math.max(0, Math.floor(Number(p.windows ?? 2)))
  const doors = Math.max(0, Math.floor(Number(p.doors ?? 2)))

  if (!(W > 0) || !(D > 0) || !(H > 0)) {
    return { ok: false, error: 'Нужны ширина, длина и высота стен (см)' }
  }

  const courses = ceilPos(H / o.log_h_cm)
  const cellsW = ceilPos(W / o.log_len_cm)
  const cellsD = ceilPos(D / o.log_len_cm)
  let outerPerCourse = 2 * (cellsW + cellsD) - 4
  if (outerPerCourse < 4) outerPerCourse = 2 * (cellsW + cellsD)

  const partitionPerCourse = partitions > 0 ? cellsW : 0
  const logsWall = (outerPerCourse + partitions * partitionPerCourse) * courses

  const t = o.wall_t_cm
  const innerW = Math.max(0, W - 2 * t)
  const innerD = Math.max(0, D - 2 * t)
  // Перегородки режут глубину: N комнат ≈ partitions+1 вдоль длины.
  const rooms = partitions + 1
  const roomDepth =
    rooms > 0 ? Math.max(0, (innerD - partitions * t) / rooms) : innerD

  const floorCm2 = innerW * innerD
  const stoneArea = o.stone_w_cm * o.stone_l_cm
  const stones = stoneArea > 0 ? ceilPos(floorCm2 / stoneArea) : 0

  const roofCm2 = W * D * o.roof_pitch
  const boardArea = o.board_w_cm * o.board_l_cm
  const roofBoards = boardArea > 0 ? ceilPos(roofCm2 / boardArea) : 0
  const frameBoards = (windows + doors) * o.frames_boards_per_opening
  const boards = roofBoards + frameBoards

  const outerWallM2 = (2 * (W + D) * H) / 10000
  const partitionM2 = (partitions * innerW * H) / 10000
  const plasterM2 = (outerWallM2 + partitionM2) * 2
  const clayKg = ceilPos(plasterM2 * o.clay_kg_per_m2)

  const curtains = windows + doors

  const laborMin = ceilPos(logsWall * o.labor_min_per_log)
  const laborWorkers = o.labor_workers

  const wallHActual = courses * o.log_h_cm

  return {
    ok: true,
    error: null,
    scheme: {
      width_cm: W,
      length_cm: D,
      height_cm: H,
      wall_h_actual_cm: wallHActual,
      courses,
      cells_w: cellsW,
      cells_d: cellsD,
      outer_logs_per_course: outerPerCourse,
      partition_logs_per_course: partitionPerCourse,
      partitions,
      rooms,
      inner_w_cm: ceil2(innerW),
      inner_d_cm: ceil2(innerD),
      room_depth_cm: ceil2(roomDepth),
      windows,
      doors,
      cube_h_cm: CUBE_HEIGHT_CM,
      door_clear_h_cm: 75,
      door_clear_w_cm: 50,
      window_clear_cm: 40,
    },
    qty: {
      brevno: logsWall,
      kamen_dlya_pola: stones,
      doska: boards,
      glina_1_kg: clayKg,
      shtory: curtains,
    },
    detail: {
      roof_boards: roofBoards,
      frame_boards: frameBoards,
      plaster_m2: ceil2(plasterM2),
      floor_m2: ceil2(floorCm2 / 10000),
      roof_m2: ceil2(roofCm2 / 10000),
    },
    labor_min: laborMin,
    labor_workers: laborWorkers,
  }
}

function ceil2(n) {
  if (n == null || !Number.isFinite(n)) return null
  return Math.round(n * 100) / 100
}

/** Рецепт для формы каталога. */
export function buildingToIngredients(calc) {
  if (!calc?.ok) return []
  const order = ['brevno', 'kamen_dlya_pola', 'doska', 'glina_1_kg', 'shtory']
  const names = {
    brevno: BUILD_MAT.log.name_ru,
    kamen_dlya_pola: BUILD_MAT.stone.name_ru,
    doska: BUILD_MAT.board.name_ru,
    glina_1_kg: BUILD_MAT.clay.name_ru,
    shtory: BUILD_MAT.curtain.name_ru,
  }
  return order
    .filter((k) => (calc.qty[k] || 0) > 0)
    .map((k, i) => ({
      ingredient_sku_key: k,
      ingredient_name_ru: names[k],
      qty: calc.qty[k],
      unit_weight: null,
      sort_order: i,
    }))
}

/** Краткая схема текстом. */
export function buildingSchemeText(calc) {
  if (!calc?.ok) return ''
  const s = calc.scheme
  const d = calc.detail
  return [
    `Сруб ${s.width_cm}×${s.length_cm}×${s.wall_h_actual_cm} см (${s.courses} рядов × ${BUILD_DEFAULTS.log_h_cm} см)`,
    `Комнат ${s.rooms} (перегородок ${s.partitions}), внутри ≈ ${s.inner_w_cm}×${s.inner_d_cm}, глубина комнаты ≈ ${s.room_depth_cm}`,
    `Проёмы: двери ${s.doors} (свет ${s.door_clear_w_cm}×${s.door_clear_h_cm}), окна ${s.windows} (≈${s.window_clear_cm}×${s.window_clear_cm}); куб ${s.cube_h_cm} см`,
    `Пол ${d.floor_m2} м² · крыша ≈ ${d.roof_m2} м² · обмазка ${d.plaster_m2} м² (2 стороны)`,
    `Сырьё: брёвна ${calc.qty.brevno}, камень пола ${calc.qty.kamen_dlya_pola}, доски ${calc.qty.doska} (крыша ${d.roof_boards}+косяки ${d.frame_boards}), глина ${calc.qty.glina_1_kg} кг, шторы ${calc.qty.shtory}`,
    `Труд (черновик): ${calc.labor_min} мин × ${calc.labor_workers} чел.`,
  ].join('\n')
}
