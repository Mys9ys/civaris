<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import CivIcon from './CivIcon.vue'
import { skuVisual } from '../icons/skuIcon'

const KINDS = [
  { key: 'raw', ru: 'сырьё' },
  { key: 'internal', ru: 'внутрь' },
  { key: 'portion', ru: 'порция' },
  { key: 'pack', ru: 'тара' },
  { key: 'tool', ru: 'инструмент' },
  { key: 'good', ru: 'конечный продукт' },
  { key: 'building', ru: 'здание' },
  { key: 'project', ru: 'проект / наука' },
  { key: 'service', ru: 'услуга' },
]

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'выберите продукт…' },
})

const emit = defineEmits(['update:modelValue', 'pick'])

const open = ref(false)
const q = ref('')
const kindFilter = ref('')
const root = ref(null)

const selected = computed(() => props.options.find((r) => r.sku_key === props.modelValue) || null)

const filtered = computed(() => {
  const needle = q.value.trim().toLowerCase()
  return props.options.filter((r) => {
    if (kindFilter.value && r.kind !== kindFilter.value) return false
    if (!needle) return true
    const name = String(r.name_ru || '').toLowerCase()
    const key = String(r.sku_key || '').toLowerCase()
    return name.includes(needle) || key.includes(needle)
  })
})

function visual(row) {
  return skuVisual(row || {})
}

function toggle() {
  open.value = !open.value
  if (open.value) {
    q.value = ''
    kindFilter.value = ''
  }
}

function choose(row) {
  emit('update:modelValue', row.sku_key)
  emit('pick', row)
  open.value = false
}

function onDoc(e) {
  if (!open.value || !root.value) return
  if (!root.value.contains(e.target)) open.value = false
}

watch(
  () => props.modelValue,
  () => {
    /* keep open state */
  }
)

onMounted(() => document.addEventListener('mousedown', onDoc))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc))
</script>

<template>
  <div ref="root" class="sku-pick" :class="{ open }">
    <button type="button" class="sku-pick-btn" @click="toggle">
      <CivIcon
        v-if="selected"
        :name="visual(selected).name"
        :mark="visual(selected).mark"
        :sku-key="selected.sku_key"
        :size="28"
      />
      <span class="sku-pick-label">{{ selected ? selected.name_ru : placeholder }}</span>
      <span class="sku-pick-chev" aria-hidden="true">▾</span>
    </button>
    <div v-if="open" class="sku-pick-menu">
      <div class="sku-pick-toolbar" @click.stop>
        <input v-model="q" class="sku-pick-search" type="search" placeholder="поиск…" />
        <select v-model="kindFilter" class="sku-pick-kind" title="Фильтр по типу">
          <option value="">тип</option>
          <option v-for="k in KINDS" :key="k.key" :value="k.key">{{ k.ru }}</option>
        </select>
      </div>
      <ul class="sku-pick-list">
        <li v-for="opt in filtered" :key="opt.sku_key">
          <button
            type="button"
            class="sku-pick-option"
            :class="{ on: opt.sku_key === modelValue }"
            @click="choose(opt)"
          >
            <CivIcon
              :name="visual(opt).name"
              :mark="visual(opt).mark"
              :sku-key="opt.sku_key"
              :size="32"
            />
            <span>{{ opt.name_ru }}</span>
          </button>
        </li>
        <li v-if="!filtered.length" class="sku-pick-empty">ничего не найдено</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.sku-pick {
  position: relative;
  min-width: 0;
}
.sku-pick-btn {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.25rem 0.45rem;
  background: #fff;
  font: inherit;
  cursor: pointer;
  text-align: left;
  min-height: 2.35rem;
}
.sku-pick-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #1a2b22;
}
.sku-pick-chev {
  color: #5a6b62;
  font-size: 0.75rem;
}
.sku-pick-menu {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  background: #fff;
  border: 1px solid #b8c9be;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(26, 43, 34, 0.12);
  max-height: 280px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.sku-pick-toolbar {
  display: flex;
  gap: 0.35rem;
  margin: 0.4rem;
  align-items: stretch;
}
.sku-pick-search {
  flex: 1;
  min-width: 0;
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.35rem 0.45rem;
  font: inherit;
}
.sku-pick-kind {
  flex: 0 0 auto;
  width: 7.2rem;
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.3rem 0.25rem;
  font: inherit;
  background: #fff;
  color: #1a2b22;
  cursor: pointer;
}
.sku-pick-list {
  list-style: none;
  margin: 0;
  padding: 0 0 0.35rem;
  overflow: auto;
}
.sku-pick-option {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: none;
  background: transparent;
  padding: 0.35rem 0.55rem;
  font: inherit;
  cursor: pointer;
  text-align: left;
  color: #1a2b22;
}
.sku-pick-option:hover,
.sku-pick-option.on {
  background: #e8f2eb;
}
.sku-pick-empty {
  padding: 0.6rem 0.75rem;
  color: #7a8b82;
  font-size: 0.85rem;
}
</style>
