<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const world = ref(null)
const settlements = ref([])
const chronicle = ref([])
const error = ref('')
const busy = ref(false)

const dateLabel = computed(() => {
  if (!world.value) return ''
  return `${world.value.day} день · ${world.value.month} месяц · ${world.value.year} год`
})

async function load() {
  error.value = ''
  const res = await api.world(props.id)
  world.value = res.world
  settlements.value = res.settlements || []
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
          </p>
        </div>
        <button type="button" class="primary" :disabled="busy" @click="advance">
          День +1
        </button>
      </div>

      <h2>Полисы</h2>
      <div class="grid">
        <article v-for="s in settlements" :key="s.id" class="card">
          <h3>{{ s.name }}</h3>
          <p>{{ s.population }} чел.</p>
          <p class="coords">{{ s.pos_x }}, {{ s.pos_y }}</p>
        </article>
      </div>

      <h2>Летопись</h2>
      <ul class="log">
        <li v-for="e in chronicle" :key="e.id">
          <span class="when">{{ e.day }}.{{ e.month }}.{{ e.year }}</span>
          {{ e.message }}
        </li>
      </ul>
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
.primary {
  border: none;
  background: #2f5d3a;
  color: #fff;
  padding: 0.65rem 1rem;
  border-radius: 3px;
  white-space: nowrap;
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
.when {
  display: inline-block;
  min-width: 4.5rem;
  color: #5a6b4e;
  font-variant-numeric: tabular-nums;
}
</style>
