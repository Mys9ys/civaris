<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'ед.' },
})

const emit = defineEmits(['update:modelValue', 'add'])

const open = ref(false)
const q = ref('')
const root = ref(null)

const filtered = computed(() => {
  const needle = q.value.trim().toLowerCase()
  const list = props.options || []
  if (!needle) return list
  return list.filter((u) => String(u).toLowerCase().includes(needle))
})

const canAdd = computed(() => {
  const t = q.value.trim()
  if (!t) return false
  return !props.options.some((u) => String(u).toLowerCase() === t.toLowerCase())
})

function toggle() {
  open.value = !open.value
  if (open.value) q.value = ''
}

function choose(unit) {
  emit('update:modelValue', unit)
  open.value = false
}

function addNew() {
  const t = q.value.trim()
  if (!t) return
  emit('add', t)
  emit('update:modelValue', t)
  open.value = false
}

function onDoc(e) {
  if (!open.value || !root.value) return
  if (!root.value.contains(e.target)) open.value = false
}

watch(
  () => props.modelValue,
  () => {}
)

onMounted(() => document.addEventListener('mousedown', onDoc))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc))
</script>

<template>
  <div ref="root" class="unit-pick" :class="{ open }">
    <button type="button" class="unit-pick-btn" @click="toggle">
      <span class="unit-pick-label">{{ modelValue || placeholder }}</span>
      <span class="unit-pick-chev" aria-hidden="true">▾</span>
    </button>
    <div v-if="open" class="unit-pick-menu">
      <input
        v-model="q"
        class="unit-pick-search"
        type="search"
        placeholder="поиск или новая…"
        @click.stop
        @keydown.enter.prevent="canAdd ? addNew() : filtered[0] && choose(filtered[0])"
      />
      <ul class="unit-pick-list">
        <li v-for="u in filtered" :key="u">
          <button
            type="button"
            class="unit-pick-option"
            :class="{ on: u === modelValue }"
            @click="choose(u)"
          >
            {{ u }}
          </button>
        </li>
        <li v-if="canAdd">
          <button type="button" class="unit-pick-option add" @click="addNew">
            + добавить «{{ q.trim() }}»
          </button>
        </li>
        <li v-if="!filtered.length && !canAdd" class="unit-pick-empty">пусто</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.unit-pick {
  position: relative;
  min-width: 0;
}
.unit-pick-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.35rem 0.45rem;
  background: #fff;
  font: inherit;
  cursor: pointer;
  text-align: left;
  min-height: 2.35rem;
}
.unit-pick-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #1a2b22;
}
.unit-pick-chev {
  color: #5a6b62;
  font-size: 0.75rem;
}
.unit-pick-menu {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  background: #fff;
  border: 1px solid #b8c9be;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(26, 43, 34, 0.12);
  max-height: 240px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.unit-pick-search {
  margin: 0.4rem;
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.35rem 0.45rem;
  font: inherit;
}
.unit-pick-list {
  list-style: none;
  margin: 0;
  padding: 0 0 0.35rem;
  overflow: auto;
}
.unit-pick-option {
  width: 100%;
  display: block;
  border: none;
  background: transparent;
  padding: 0.4rem 0.55rem;
  font: inherit;
  cursor: pointer;
  text-align: left;
  color: #1a2b22;
}
.unit-pick-option:hover,
.unit-pick-option.on {
  background: #e8f2eb;
}
.unit-pick-option.add {
  color: #2f5d3a;
  font-weight: 600;
}
.unit-pick-empty {
  padding: 0.6rem 0.75rem;
  color: #7a8b82;
  font-size: 0.85rem;
}
</style>
