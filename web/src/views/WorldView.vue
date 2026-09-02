<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const world = ref(null)
const user = ref(null)
const settlements = ref([])
const peopleCount = ref(0)
const chronicle = ref([])
const error = ref('')
const busy = ref(false)

const dateLabel = computed(() => {
  if (!world.value) return ''
  const w = world.value
  const parts = [`${w.day} день`]
  if (w.week) parts.push(`${w.week} неделя`)
  parts.push(`${w.month} месяц`, `${w.year} год`)
  return parts.join(' · ')
})

function inventoryLabel(items, { crops = false } = {}) {
  if (!items?.length) return crops ? '—' : 'пусто'
  return items
    .map((row) => {
      const icon = row.emoji ? `${row.emoji} ` : ''
      const name = row.name_ru || row.sku_key
      if (crops || row.qty == null) {
        return row.infinite ? `${icon}${name} (∞)` : `${icon}${name}`
      }
      const unit = row.unit ? ` ${row.unit}` : ''
      return `${icon}${name}: ${Number(row.qty)}${unit}`
    })
    .join(' · ')
}

const expandedGroups = ref(new Set())

const chronicleGroups = computed(() => {
  const map = new Map()
  for (const e of chronicle.value) {
    const id = e.settlement_id ?? 0
    const label = e.settlement_name || 'Общее'
    if (!map.has(id)) {
      map.set(id, { id, label, events: [] })
    }
    map.get(id).events.push(e)
  }
  return [...map.values()].sort((a, b) => {
    if (a.id === 0) return 1
    if (b.id === 0) return -1
    return a.id - b.id
  })
})

function eventCountLabel(n) {
  const mod10 = n % 10
  const mod100 = n % 100
  if (mod10 === 1 && mod100 !== 11) return `${n} событие`
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return `${n} события`
  return `${n} событий`
}

function toggleGroup(id) {
  const next = new Set(expandedGroups.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  expandedGroups.value = next
}

function jobsLabel(jobs) {
  if (!jobs?.length) return '—'
  return jobs.map((row) => `${row.count} ${row.label}`).join(' · ')
}

function gatherModLabel(s) {
  if (s.gather_mod_kcal == null) return ''
  return `угодья ×${s.gather_mod_kcal} еда · ×${s.gather_mod_water} вода`
}

async function load() {
  error.value = ''
  const me = await api.me()
  user.value = me.user
  const res = await api.world(props.id)
  world.value = res.world
  settlements.value = res.settlements || []
  peopleCount.value = res.people_count ?? 0
  const ch = await api.chronicle(props.id)
  chronicle.value = ch.chronicle || []
}

async function advance() {
  busy.value = true
  error.value = ''
  try {
    const res = await api.advanceDay(props.id)
    world.value = res.world
    chronicle.value = res.chronicle || []
    const detail = await api.world(props.id)
    settlements.value = detail.settlements || []
    peopleCount.value = detail.people_count ?? 0
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function resetWorld() {
  if (!window.confirm('Сбросить мир? Календарь 1.1.1, угодья, склады и летопись — как при создании.')) {
    return
  }
  busy.value = true
  error.value = ''
  try {
    const res = await api.resetWorld(props.id)
    world.value = res.world
    settlements.value = res.settlements || []
    peopleCount.value = res.people_count ?? 0
    chronicle.value = res.chronicle || []
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  load().catch((e) => {
    error.value = e.message
  })
})

watch(
  () => props.id,
  () => {
    load().catch((e) => {
      error.value = e.message
    })
  }
)
</script>

<template>
  <section class="panel">
    <router-link class="back" to="/">← к мирам</router-link>

    <p v-if="error" class="err">{{ error }}</p>

    <template v-if="world">
      <div class="head">
        <div>
          <h1>{{ world.name }}</h1>
          <p class="date">{{ dateLabel }}</p>
          <p class="meta">
            Население {{ world.population }} / лимит {{ world.population_cap }}
            · люди в БД: {{ peopleCount }}
            <template v-if="world.map_size"> · карта {{ world.map_size }}</template>
            <template v-if="world.map_seed"> · seed {{ world.map_seed }}</template>
          </p>
        </div>
        <div class="actions">
          <button type="button" class="primary" :disabled="busy" @click="advance">
            День +1
          </button>
          <button
            v-if="user?.role === 'admin'"
            type="button"
            class="danger"
            :disabled="busy"
            @click="resetWorld"
          >
            Сбросить мир
          </button>
        </div>
      </div>

      <h2>Деревни</h2>
      <div class="grid">
        <article v-for="s in settlements" :key="s.id" class="card">
          <h3>{{ s.name }}</h3>
          <p>{{ s.population }} чел. · провинция #{{ s.province_id }}</p>
          <p class="coords">{{ s.housing_kind }} на {{ s.housing_capacity }}</p>
          <p v-if="gatherModLabel(s)" class="mods">{{ gatherModLabel(s) }}</p>
          <div class="inv">
            <span class="inv-label">Занятость:</span>
            <span class="inv-body">{{ jobsLabel(s.jobs) }}</span>
          </div>
          <div class="inv">
            <span class="inv-label">Угодья:</span>
            <span class="inv-body">{{ inventoryLabel(s.ugodiya, { crops: true }) }}</span>
          </div>
          <div class="inv">
            <span class="inv-label">Склад:</span>
            <span class="inv-body">{{ inventoryLabel(s.stock) }}</span>
          </div>
        </article>
      </div>

      <h2>Летопись</h2>
      <div class="chrono">
        <section v-for="g in chronicleGroups" :key="g.id" class="chrono-group">
          <button type="button" class="chrono-head" @click="toggleGroup(g.id)">
            <span class="chev">{{ expandedGroups.has(g.id) ? '▼' : '▶' }}</span>
            {{ g.label }}
            <span class="chrono-count">({{ eventCountLabel(g.events.length) }})</span>
          </button>
          <ul v-show="expandedGroups.has(g.id)" class="log">
            <li v-for="e in g.events" :key="e.id">
              <span class="when">{{ e.day }}.{{ e.month }}.{{ e.year }}</span>
              <span class="log-msg">{{ e.message }}</span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.panel {
  background: rgba(255, 252, 245, 0.85);
  border: 1px solid #b7c4a4;
  border-radius: 4px;
  padding: 1.25rem 1.35rem 1.5rem;
}
.back {
  display: inline-block;
  margin-bottom: 0.75rem;
  color: #2f5d3a;
}
.head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 1.25rem;
}
.actions {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  align-items: stretch;
}
.primary {
  border: none;
  background: #2f5d3a;
  color: #fff;
  padding: 0.65rem 1rem;
  border-radius: 3px;
  white-space: nowrap;
}
.danger {
  border: 1px solid #a44;
  background: #fff5f5;
  color: #8b2e2e;
  padding: 0.5rem 0.85rem;
  border-radius: 3px;
  font-size: 0.88rem;
  white-space: nowrap;
}
.danger:disabled,
.primary:disabled {
  opacity: 0.6;
}
h1 {
  margin: 0;
  font-size: 1.45rem;
  color: #1f3d28;
}
.date {
  margin: 0.25rem 0 0;
  font-size: 1.05rem;
}
.meta {
  margin: 0.2rem 0 0;
  color: #5a6b4e;
  font-size: 0.9rem;
}
.err {
  color: #8b2e2e;
  background: #f7e4e4;
  padding: 0.5rem 0.75rem;
  border-radius: 3px;
}
h2 {
  margin: 1rem 0 0.5rem;
  font-size: 1.05rem;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 0.5rem;
}
.card {
  border: 1px solid #c5d0b5;
  background: #f7f4ea;
  padding: 0.55rem 0.65rem;
  border-radius: 3px;
}
.card h3 {
  margin: 0 0 0.2rem;
  font-size: 0.95rem;
}
.card p {
  margin: 0;
  font-size: 0.85rem;
}
.coords {
  color: #6a7760;
}
.mods {
  color: #4a6b52;
  font-size: 0.78rem;
  margin-top: 0.2rem;
}
.inv {
  margin-top: 0.35rem;
  color: #3e4a36;
  font-size: 0.78rem;
  line-height: 1.35;
}
.inv-label {
  display: block;
  color: #5a6b4e;
  font-weight: 600;
}
.inv-body {
  display: block;
  margin-top: 0.15rem;
}
.chrono {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.chrono-group {
  border: 1px solid #d5ddc8;
  border-radius: 3px;
  background: #faf8f2;
}
.chrono-head {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  border: none;
  background: transparent;
  padding: 0.55rem 0.65rem;
  font-size: 0.92rem;
  color: #1f3d28;
  text-align: left;
  cursor: pointer;
}
.chrono-head:hover {
  background: #f0ede4;
}
.chev {
  color: #5a6b4e;
  font-size: 0.75rem;
  width: 0.9rem;
}
.chrono-count {
  color: #6a7760;
  font-size: 0.85rem;
}
.chrono-group .log {
  border-top: 1px solid #e3e9d8;
  padding: 0 0.65rem 0.35rem;
}
.log {
  list-style: none;
  padding: 0;
  margin: 0;
}
.log li {
  padding: 0.45rem 0;
  border-bottom: 1px solid #d5ddc8;
  font-size: 0.92rem;
}
.log-msg {
  display: block;
  margin-top: 0.15rem;
  white-space: pre-line;
  line-height: 1.45;
}
.when {
  display: inline-block;
  min-width: 4.5rem;
  color: #5a6b4e;
  font-variant-numeric: tabular-nums;
}
</style>
