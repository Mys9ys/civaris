<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'
import CivIcon from '../components/CivIcon.vue'
import CubeAvatar from '../components/CubeAvatar.vue'
import SettlementLayoutMap from '../components/SettlementLayoutMap.vue'
import { skuIcon, skuVisual, extractMark } from '../icons/skuIcon.js'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const TABS = [
  { id: 'layout', label: 'Застройка' },
  { id: 'people', label: 'Люди' },
  { id: 'firms', label: 'Фирмы' },
  { id: 'stock', label: 'Склад' },
  { id: 'money', label: 'Деньги' },
]

const world = ref(null)
const user = ref(null)
const settlementId = ref(null)
const settlementName = ref('Макленд')
const monitor = ref(null)
const chronicle = ref([])
const error = ref('')
const busy = ref(false)
const tab = ref('layout')
const selectedPerson = ref(null)
const chronicleOpen = ref(false)
const chronicleDayKey = ref('')

const dateShort = computed(() => {
  const w = world.value
  if (!w) return '—'
  return `${w.day}.${w.month}.${w.year}`
})

const dateHover = computed(() => {
  const w = world.value
  if (!w) return ''
  const parts = []
  if (w.weekday_name) parts.push(w.weekday_name)
  if (w.is_rest_day) parts.push('выходной')
  parts.push(`${w.day} день`, `${w.month} месяц`, `${w.year} год`)
  if (w.week) parts.push(`(${w.week} неделя)`)
  return parts.join(' · ')
})

const pop = computed(() => monitor.value?.population || null)
const people = computed(() => pop.value?.people || [])
const finance = computed(() => monitor.value?.finance || null)
const companies = computed(() => monitor.value?.companies || [])
const stock = computed(() => monitor.value?.resources?.stock || [])
const ugodiya = computed(() => monitor.value?.resources?.ugodiya || [])
const ledger = computed(() => monitor.value?.ledger || [])
const layout = computed(() => monitor.value?.layout || null)

const chronicleDays = computed(() => {
  const map = new Map()
  for (const e of chronicle.value) {
    const key = `${e.day}.${e.month}.${e.year}`
    if (!map.has(key)) {
      map.set(key, {
        key,
        day: e.day,
        month: e.month,
        year: e.year,
        events: [],
      })
    }
    map.get(key).events.push(e)
  }
  return [...map.values()]
})

const chronicleEvents = computed(() => {
  const days = chronicleDays.value
  if (!days.length) return []
  const key = chronicleDayKey.value || days[0].key
  const hit = days.find((d) => d.key === key)
  return hit?.events || []
})

function money(v) {
  const n = Number(v)
  return Number.isFinite(n) ? n.toFixed(2) : '—'
}

function energyTitle(band) {
  if (band === 'good') return 'сыт / энергия ок'
  if (band === 'mid') return 'средне'
  return 'голод / низкая энергия'
}

function openPerson(person) {
  selectedPerson.value = person
}

function closePerson() {
  selectedPerson.value = null
}

function onLayoutUpdated(next) {
  if (!monitor.value) return
  monitor.value = { ...monitor.value, layout: next }
}

function stockItems(rows) {
  if (!rows?.length) return []
  return rows.map((r) => {
    const v = skuVisual({
      sku_key: r.sku_key,
      name_ru: r.name_ru,
      icon_key: r.icon_key,
      icon_mark: r.icon_mark,
      is_shop: r.is_shop,
      kind: r.kind,
      price_shop: r.price_shop,
      sell_channel: r.sell_channel,
    })
    return {
      key: r.sku_key,
      icon: v.name,
      mark: v.mark || extractMark(r.name_ru),
      shop: v.shop,
      tintKey: v.tintKey,
      name: r.name_ru || r.sku_key,
      text: `${Number(r.qty)}${r.unit ? ` ${r.unit}` : ''}`,
    }
  })
}

function ugodiyaItems(rows) {
  if (!rows?.length) return []
  return rows.map((r) => {
    const v = skuVisual({ sku_key: r.sku_key, name_ru: r.name_ru })
    return {
      key: r.sku_key,
      icon: v.name || skuIcon(r.sku_key),
      mark: v.mark,
      shop: false,
      tintKey: v.tintKey,
      name: r.name_ru || r.sku_key,
      text: r.infinite ? '∞' : '',
    }
  })
}

function openChronicle() {
  chronicleOpen.value = true
  if (!chronicleDayKey.value && chronicleDays.value.length) {
    chronicleDayKey.value = chronicleDays.value[0].key
  }
}

function closeChronicle() {
  chronicleOpen.value = false
}

async function loadMonitor() {
  if (!settlementId.value) return
  const res = await api.settlementMonitor(props.id, settlementId.value)
  world.value = res.world
  monitor.value = res.monitor
  settlementName.value = res.monitor?.settlement?.name || 'Макленд'
}

async function load() {
  error.value = ''
  busy.value = true
  try {
    const me = await api.me()
    user.value = me.user
    const res = await api.world(props.id)
    world.value = res.world
    const first = (res.settlements || [])[0]
    if (!first) {
      throw new Error('В мире нет поселения')
    }
    settlementId.value = first.id
    settlementName.value = first.name || 'Макленд'
    await loadMonitor()
    const ch = await api.chronicle(props.id)
    chronicle.value = ch.chronicle || []
    if (chronicleDays.value.length) {
      chronicleDayKey.value = chronicleDays.value[0].key
    }
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function advance() {
  busy.value = true
  error.value = ''
  try {
    const res = await api.advanceDay(props.id)
    world.value = res.world
    chronicle.value = res.chronicle || []
    await loadMonitor()
    if (chronicleDays.value.length) {
      chronicleDayKey.value = chronicleDays.value[0].key
    }
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
    chronicle.value = res.chronicle || []
    const first = (res.settlements || [])[0]
    if (first) {
      settlementId.value = first.id
      settlementName.value = first.name || 'Макленд'
    }
    await loadMonitor()
    if (chronicleDays.value.length) {
      chronicleDayKey.value = chronicleDays.value[0].key
    }
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
    tab.value = 'layout'
    chronicleOpen.value = false
    load().catch((e) => {
      error.value = e.message
    })
  }
)
</script>

<template>
  <section class="panel">
    <div class="top-row">
      <router-link class="back" to="/">← к мирам</router-link>
      <div class="top-actions">
        <router-link class="chip-link" to="/catalog">Каталог · цены</router-link>
        <button
          v-if="user?.role === 'admin'"
          type="button"
          class="chip-btn danger"
          :disabled="busy"
          @click="resetWorld"
        >
          Сброс
        </button>
      </div>
    </div>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="busy && !monitor" class="meta">Загрузка Макленда…</p>

    <template v-if="world">
      <header class="head">
        <div class="head-main">
          <h1>{{ settlementName }}</h1>
          <p class="meta meta-row">
            <span class="metric">
              <CivIcon name="population" label="Население" :size="16" />
              {{ pop?.alive ?? world.population }} / {{ pop?.housing_capacity ?? world.population_cap }}
            </span>
            <span class="dot">·</span>
            <span class="metric">
              <CivIcon name="coin" label="Казна" :size="16" />
              {{ money(finance?.treasury) }}
            </span>
          </p>
        </div>

        <div class="clock-cluster" title="">
          <button
            type="button"
            class="clock-icon"
            :title="'Летопись'"
            aria-label="Летопись"
            @click="openChronicle"
          >
            <CivIcon name="book" label="Летопись" :size="18" />
          </button>
          <div class="clock" :title="dateHover">
            <span class="clock-date">{{ dateShort }}</span>
          </div>
          <button type="button" class="day-plus" :disabled="busy" :title="dateHover" @click="advance">
            + день
          </button>
        </div>
      </header>

      <nav class="menu" aria-label="Разделы Макленда">
        <button
          v-for="t in TABS"
          :key="t.id"
          type="button"
          :class="{ on: tab === t.id }"
          @click="tab = t.id"
        >
          {{ t.label }}
        </button>
      </nav>

      <div v-if="monitor" class="section">
        <template v-if="tab === 'layout'">
          <div class="section-head">
            <h2>Карта застройки</h2>
            <p class="meta">
              Жильё {{ pop?.housing_occupied ?? 0 }} / {{ pop?.housing_capacity ?? 0 }}
              <span class="muted">({{ pop?.housing_kind || '—' }})</span>
            </p>
          </div>
          <SettlementLayoutMap
            v-if="layout && settlementId"
            :layout="layout"
            :world-id="id"
            :settlement-id="settlementId"
            @updated="onLayoutUpdated"
            @error="(m) => (error = m)"
          />
          <p v-else class="muted">Карта появится после обновления мира / щелчка.</p>
        </template>

        <template v-else-if="tab === 'people'">
          <h2><CivIcon name="population" label="Люди" /> Люди</h2>
          <dl class="stats compact">
            <div>
              <dt>Живых</dt>
              <dd>{{ pop?.alive ?? '—' }}</dd>
            </div>
            <div>
              <dt>Трудоустроены</dt>
              <dd>{{ pop?.employed ?? '—' }}</dd>
            </div>
            <div>
              <dt>Без роли</dt>
              <dd>{{ pop?.unemployed ?? '—' }}</dd>
            </div>
            <div>
              <dt>Ср. энергия</dt>
              <dd>{{ pop?.avg_energy ?? '—' }}</dd>
            </div>
          </dl>
          <div class="people-table">
            <div class="people-head">
              <span>#</span>
              <span>Имя</span>
              <span>Роль</span>
              <span>Энергия</span>
              <span><CivIcon name="coin" label="Кошелёк" :size="14" /></span>
              <span>Статус</span>
            </div>
            <div
              v-for="(p, i) in people"
              :key="p.id"
              class="people-row"
              :class="{ dead: p.status !== 'alive' }"
            >
              <span class="muted">{{ i + 1 }}</span>
              <strong class="person-name" @click="openPerson(p)">
                <CubeAvatar :person-id="p.id" :name="p.name" :size="22" />
                {{ p.name }}
              </strong>
              <span class="metric">
                <CivIcon :name="p.job_key ? 'workers' : 'vacancy'" :size="14" />
                {{ p.job_label }}
              </span>
              <span class="energy" :class="p.energy_band" :title="energyTitle(p.energy_band)">
                <span class="energy-bar"><i :style="{ width: Math.min(100, p.energy) + '%' }" /></span>
                {{ p.energy }}
              </span>
              <span class="metric"><CivIcon name="coin" :size="14" /> {{ money(p.cash) }}</span>
              <span>{{ p.status === 'alive' ? 'жив' : p.status }}</span>
            </div>
            <p v-if="!people.length" class="muted">никого нет</p>
          </div>
        </template>

        <template v-else-if="tab === 'firms'">
          <h2><CivIcon name="firm" label="Фирмы" /> Фирмы</h2>
          <p class="meta">
            Всего {{ monitor.companies_summary?.total ?? 0 }} ·
            <CivIcon name="credit" label="Кредиты" :size="14" />
            {{ money(monitor.companies_summary?.credit_sum) }} ·
            <CivIcon name="coin" label="Кассы" :size="14" />
            {{ money(monitor.companies_summary?.firm_cash_sum) }}
          </p>
          <div class="co-table">
            <div class="co-head">
              <span>Код</span>
              <span><CivIcon name="workers" label="Работники" :size="14" /></span>
              <span><CivIcon name="coin" label="Касса" :size="14" /></span>
              <span><CivIcon name="credit" label="Кредит" :size="14" /></span>
            </div>
            <div v-for="c in companies" :key="c.id" class="co-row">
              <strong class="co-code" :title="c.description || c.title || ''">{{ c.code }}</strong>
              <div class="staff">
                <CubeAvatar
                  v-for="p in c.staff || []"
                  :key="p.id"
                  :person-id="p.id"
                  :name="p.name"
                  :size="26"
                  interactive
                  @select="openPerson(p)"
                />
                <span v-if="!(c.staff || []).length" class="muted">—</span>
              </div>
              <span class="metric"><CivIcon name="coin" :size="14" /> {{ money(c.balance) }}</span>
              <span class="metric">
                <template v-if="Number(c.credit_principal) > 0">
                  <CivIcon name="credit" :size="14" /> {{ money(c.credit_principal) }}
                </template>
                <template v-else>—</template>
              </span>
            </div>
          </div>
        </template>

        <template v-else-if="tab === 'stock'">
          <h2><CivIcon name="stock" label="Склад" /> Склад</h2>
          <ul v-if="stockItems(stock).length" class="chips">
            <li v-for="item in stockItems(stock)" :key="item.key">
              <CivIcon
                :name="item.icon"
                :mark="item.mark"
                :shop="item.shop"
                :tint-key="item.tintKey"
                :sku-key="item.key"
                :label="item.name"
                :size="28"
              />
              <span>{{ item.name }}: {{ item.text }}</span>
            </li>
          </ul>
          <p v-else class="muted">пусто</p>
          <h3 class="sub">Угодья</h3>
          <ul v-if="ugodiyaItems(ugodiya).length" class="chips">
            <li v-for="item in ugodiyaItems(ugodiya)" :key="item.key">
              <CivIcon
                :name="item.icon"
                :mark="item.mark"
                :tint-key="item.tintKey"
                :sku-key="item.key"
                :label="item.name"
                :size="22"
              />
              <span>{{ item.name }}<template v-if="item.text"> ({{ item.text }})</template></span>
            </li>
          </ul>
          <p v-else class="muted">—</p>
        </template>

        <template v-else-if="tab === 'money'">
          <h2><CivIcon name="coin" label="Деньги" /> Деньги</h2>
          <dl class="stats">
            <div>
              <dt><CivIcon name="coin" label="Казна" /> Казна</dt>
              <dd>{{ money(finance?.treasury) }}</dd>
            </div>
            <div>
              <dt>
                <CivIcon name="credit" :label="finance?.bank_code || 'ЧБ'" />
                {{ finance?.bank_code || 'ЧБ' }}
              </dt>
              <dd>{{ money(finance?.bank) }}</dd>
            </div>
            <div>
              <dt><CivIcon name="firm" label="Кассы фирм" /> Кассы фирм</dt>
              <dd>{{ money(finance?.firms_cash) }}</dd>
            </div>
            <div>
              <dt><CivIcon name="population" label="Кошельки" /> Кошельки людей</dt>
              <dd>{{ money(finance?.people_cash) }}</dd>
            </div>
            <div>
              <dt><CivIcon name="coin" label="Денежная масса" /> Денежная масса</dt>
              <dd>{{ money(finance?.money_mass) }}</dd>
            </div>
          </dl>
          <p v-if="finance?.note" class="note">{{ finance.note }}</p>
          <h3 class="sub">Сделки (ledger)</h3>
          <ul class="ledger">
            <li v-for="e in ledger" :key="e.id">
              <span class="when">{{ e.day }}.{{ e.month }}.{{ e.year }}</span>
              <span class="kind">{{ e.kind }}</span>
              <span class="amt">{{ money(e.amount) }}</span>
              <span class="note-cell">{{ e.note }}</span>
            </li>
            <li v-if="!ledger.length" class="muted">Сделок пока нет</li>
          </ul>
        </template>
      </div>
    </template>

    <div
      v-if="chronicleOpen"
      class="drawer-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label="Летопись"
      @click.self="closeChronicle"
    >
      <aside class="drawer">
        <header class="drawer-head">
          <h2>
            <CivIcon name="book" label="Летопись" :size="18" />
            Летопись
          </h2>
          <button type="button" class="chip-btn" @click="closeChronicle">Закрыть</button>
        </header>
        <div class="drawer-body">
          <div class="day-list">
            <button
              v-for="d in chronicleDays"
              :key="d.key"
              type="button"
              :class="{ on: (chronicleDayKey || chronicleDays[0]?.key) === d.key }"
              @click="chronicleDayKey = d.key"
            >
              {{ d.key }}
              <span class="muted">{{ d.events.length }}</span>
            </button>
            <p v-if="!chronicleDays.length" class="muted">Пока пусто</p>
          </div>
          <ul class="log">
            <li v-for="e in chronicleEvents" :key="e.id">
              <span class="when">{{ e.day }}.{{ e.month }}.{{ e.year }}</span>
              <span class="log-msg">{{ e.message }}</span>
            </li>
            <li v-if="!chronicleEvents.length" class="muted">Нет событий за этот день</li>
          </ul>
        </div>
      </aside>
    </div>

    <div
      v-if="selectedPerson"
      class="person-backdrop"
      role="dialog"
      aria-modal="true"
      @click.self="closePerson"
    >
      <div class="person-card">
        <button type="button" class="person-close" aria-label="Закрыть" @click="closePerson">×</button>
        <div class="person-hero">
          <CubeAvatar :person-id="selectedPerson.id" :name="selectedPerson.name" :size="72" />
          <div>
            <h3>{{ selectedPerson.name }}</h3>
            <p class="meta">{{ selectedPerson.job_label }}</p>
          </div>
        </div>
        <dl class="stats person-stats">
          <div>
            <dt>Энергия</dt>
            <dd>
              <span class="energy" :class="selectedPerson.energy_band">
                <span class="energy-bar">
                  <i :style="{ width: Math.min(100, selectedPerson.energy) + '%' }" />
                </span>
                {{ selectedPerson.energy }}
              </span>
            </dd>
          </div>
          <div>
            <dt><CivIcon name="coin" label="Кошелёк" /> Кошелёк</dt>
            <dd>{{ money(selectedPerson.cash) }}</dd>
          </div>
          <div>
            <dt>Статус</dt>
            <dd>{{ selectedPerson.status === 'alive' ? 'жив' : selectedPerson.status }}</dd>
          </div>
          <div>
            <dt>ID</dt>
            <dd>#{{ selectedPerson.id }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </section>
</template>

<style scoped>
.panel {
  background: rgba(255, 252, 245, 0.9);
  border: 1px solid #b7c4a4;
  border-radius: 4px;
  padding: 1rem 1.15rem 1.35rem;
}
.top-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.55rem;
}
.back {
  color: #2f5d3a;
}
.top-actions {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}
.chip-link,
.chip-btn {
  border: 1px solid #9aab8c;
  background: #fff;
  color: #2f3a2c;
  border-radius: 3px;
  padding: 0.28rem 0.55rem;
  font-size: 0.82rem;
  text-decoration: none;
  cursor: pointer;
}
.chip-btn.danger {
  border-color: #a44;
  background: #fff5f5;
  color: #8b2e2e;
}
.chip-btn:disabled {
  opacity: 0.6;
}
.head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 0.85rem;
}
.head-main h1 {
  margin: 0;
  font-size: 1.35rem;
  color: #1f3d28;
}
.meta {
  margin: 0.2rem 0 0;
  color: #5a6b4e;
  font-size: 0.88rem;
}
.meta-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.35rem;
}
.dot {
  opacity: 0.5;
}
.metric {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  font-variant-numeric: tabular-nums;
}
.clock-cluster {
  display: flex;
  align-items: stretch;
  gap: 0.35rem;
  flex-shrink: 0;
}
.clock-icon,
.day-plus {
  border: 1px solid #9aab8c;
  background: #fff;
  color: #2f3a2c;
  border-radius: 3px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 0.55rem;
}
.day-plus {
  font-weight: 600;
  font-size: 0.86rem;
  white-space: nowrap;
}
.day-plus:disabled {
  opacity: 0.55;
}
.clock {
  min-width: 3.6rem;
  padding: 0.4rem 0.55rem;
  border: 1px solid #b7c4a4;
  background: #f7f4ea;
  border-radius: 3px;
  text-align: center;
  cursor: default;
}
.clock-date {
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  font-size: 1.05rem;
  color: #1f3d28;
  letter-spacing: 0.02em;
}
.menu {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-bottom: 0.85rem;
  border-bottom: 1px solid #d5ddc8;
  padding-bottom: 0.55rem;
}
.menu button {
  border: 1px solid transparent;
  background: transparent;
  color: #5a6b4e;
  border-radius: 3px;
  padding: 0.4rem 0.7rem;
  cursor: pointer;
  font-size: 0.9rem;
}
.menu button.on {
  background: #2f5d3a;
  color: #fff;
}
.section h2 {
  margin: 0 0 0.55rem;
  font-size: 1.02rem;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.section-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.35rem 1rem;
  align-items: baseline;
  margin-bottom: 0.45rem;
}
.sub {
  margin: 0.85rem 0 0.35rem;
  font-size: 0.88rem;
  color: #5a6b4e;
}
.err {
  color: #8b2e2e;
  background: #fff5f5;
  padding: 0.5rem 0.7rem;
  border-radius: 3px;
}
.muted {
  color: #6a7760;
}
.stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem 0.75rem;
  margin: 0 0 0.75rem;
}
.stats.compact {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
.stats div {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}
.stats dt {
  font-size: 0.75rem;
  color: #6a7760;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}
.stats dd {
  margin: 0;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.list {
  margin: 0;
  padding: 0;
  list-style: none;
}
.list li {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0.2rem 0;
  font-size: 0.9rem;
}
.chips {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.55rem;
}
.chips li {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.86rem;
  background: #fff;
  border: 1px solid #d5ddc8;
  border-radius: 3px;
  padding: 0.2rem 0.45rem;
}
.note {
  margin: 0.5rem 0 0;
  font-size: 0.8rem;
  color: #6a7760;
  line-height: 1.35;
}
.co-table {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.82rem;
}
.co-head,
.co-row {
  display: grid;
  grid-template-columns: 6.2rem minmax(5.5rem, 1fr) minmax(4.5rem, auto) minmax(4.5rem, auto);
  gap: 0.35rem;
  align-items: center;
}
.co-head {
  color: #6a7760;
  font-size: 0.75rem;
  border-bottom: 1px solid #d5ddc8;
  padding-bottom: 0.25rem;
}
.co-row {
  padding: 0.35rem 0;
  border-bottom: 1px solid #e3e9d8;
}
.co-code {
  cursor: help;
  border-bottom: 1px dotted #9aab8c;
}
.staff {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  align-items: center;
  min-height: 28px;
}
.people-table {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.84rem;
  max-height: 480px;
  overflow: auto;
}
.people-head,
.people-row {
  display: grid;
  grid-template-columns: 2rem 7.5rem 1fr 5.5rem 5rem 3.2rem;
  gap: 0.4rem;
  align-items: center;
}
.people-head {
  color: #6a7760;
  font-size: 0.75rem;
  border-bottom: 1px solid #d5ddc8;
  padding-bottom: 0.25rem;
  position: sticky;
  top: 0;
  background: #fffcf5;
  z-index: 1;
}
.people-row {
  padding: 0.28rem 0;
  border-bottom: 1px solid #e3e9d8;
}
.people-row.dead {
  opacity: 0.55;
}
.person-name {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  cursor: pointer;
}
.person-name:hover {
  color: #2f5d3a;
}
.energy {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.energy-bar {
  display: inline-block;
  width: 2.4rem;
  height: 0.45rem;
  background: #dde5d2;
  border-radius: 2px;
  overflow: hidden;
}
.energy-bar i {
  display: block;
  height: 100%;
  background: #2f5d3a;
}
.energy.mid .energy-bar i {
  background: #b0892e;
}
.energy.low .energy-bar i {
  background: #a04444;
}
.ledger {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 320px;
  overflow: auto;
}
.ledger li {
  display: grid;
  grid-template-columns: 4.5rem 6.5rem 4.5rem 1fr;
  gap: 0.45rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid #e3e9d8;
  font-size: 0.82rem;
}
.when {
  color: #5a6b4e;
  font-variant-numeric: tabular-nums;
}
.kind {
  color: #2f5d3a;
  font-weight: 600;
}
.amt {
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.note-cell {
  color: #3e4a36;
}
.drawer-backdrop,
.person-backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  background: rgba(31, 61, 40, 0.28);
  display: grid;
  place-items: center;
  padding: 1rem;
}
.drawer {
  width: min(720px, 100%);
  max-height: min(78vh, 640px);
  background: #fffcf5;
  border: 1px solid #b7c4a4;
  border-radius: 6px;
  box-shadow: 0 10px 28px rgba(31, 61, 40, 0.18);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.drawer-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0.9rem;
  border-bottom: 1px solid #d5ddc8;
}
.drawer-head h2 {
  margin: 0;
  font-size: 1.05rem;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.drawer-body {
  display: grid;
  grid-template-columns: 8.5rem 1fr;
  min-height: 0;
  flex: 1;
}
.day-list {
  border-right: 1px solid #e3e9d8;
  overflow: auto;
  padding: 0.4rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.day-list button {
  border: 1px solid transparent;
  background: transparent;
  text-align: left;
  border-radius: 3px;
  padding: 0.4rem 0.45rem;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  gap: 0.35rem;
  font-size: 0.86rem;
  font-variant-numeric: tabular-nums;
}
.day-list button.on {
  background: #eef3e6;
  border-color: #c5d0b5;
}
.log {
  list-style: none;
  margin: 0;
  padding: 0.55rem 0.75rem;
  overflow: auto;
}
.log li {
  display: grid;
  grid-template-columns: 4.2rem 1fr;
  gap: 0.5rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid #eef2e6;
  font-size: 0.86rem;
}
.log-msg {
  color: #2f3a2c;
  line-height: 1.35;
}
.person-card {
  position: relative;
  width: min(360px, 100%);
  background: #fffcf5;
  border: 1px solid #b7c4a4;
  border-radius: 6px;
  padding: 1rem 1.1rem 1.15rem;
  box-shadow: 0 10px 28px rgba(31, 61, 40, 0.18);
}
.person-close {
  position: absolute;
  top: 0.35rem;
  right: 0.45rem;
  border: none;
  background: transparent;
  color: #5a6b4e;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
}
.person-hero {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  margin-bottom: 0.85rem;
}
.person-hero h3 {
  margin: 0 0 0.2rem;
  font-size: 1.1rem;
}
.person-stats {
  grid-template-columns: 1fr 1fr;
}
@media (max-width: 700px) {
  .head {
    flex-direction: column;
  }
  .clock-cluster {
    align-self: flex-end;
  }
  .stats.compact {
    grid-template-columns: 1fr 1fr;
  }
  .drawer-body {
    grid-template-columns: 1fr;
  }
  .day-list {
    border-right: none;
    border-bottom: 1px solid #e3e9d8;
    flex-direction: row;
    overflow-x: auto;
  }
  .ledger li {
    grid-template-columns: 1fr 1fr;
  }
  .co-head,
  .co-row {
    grid-template-columns: 5rem 1fr 4rem;
  }
  .co-head span:nth-child(4),
  .co-row > span:nth-child(4) {
    display: none;
  }
  .people-head,
  .people-row {
    grid-template-columns: 1.5rem 1fr 4.5rem;
  }
  .people-head span:nth-child(3),
  .people-head span:nth-child(5),
  .people-head span:nth-child(6),
  .people-row > span:nth-child(3),
  .people-row > span:nth-child(5),
  .people-row > span:nth-child(6) {
    display: none;
  }
}
</style>
