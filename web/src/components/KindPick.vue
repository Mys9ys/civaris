<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import CivIcon from './CivIcon.vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'тип…' },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const root = ref(null)

function selected() {
  return props.options.find((k) => k.key === props.modelValue) || null
}

function toggle() {
  open.value = !open.value
}

function choose(key) {
  emit('update:modelValue', key)
  open.value = false
}

function onDoc(e) {
  if (!open.value || !root.value) return
  if (!root.value.contains(e.target)) open.value = false
}

onMounted(() => document.addEventListener('mousedown', onDoc))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc))
</script>

<template>
  <div ref="root" class="kind-pick" :class="{ open }">
    <button type="button" class="kind-pick-btn" @click="toggle">
      <CivIcon v-if="selected()" :name="selected().icon" :size="22" />
      <span class="kind-pick-label">{{ selected()?.ru || placeholder }}</span>
      <span class="kind-pick-chev" aria-hidden="true">▾</span>
    </button>
    <div v-if="open" class="kind-pick-menu">
      <ul class="kind-pick-list">
        <li v-for="k in options" :key="k.key">
          <button
            type="button"
            class="kind-pick-option"
            :class="{ on: k.key === modelValue }"
            @click="choose(k.key)"
          >
            <CivIcon :name="k.icon" :size="24" />
            <span>{{ k.ru }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.kind-pick {
  position: relative;
  min-width: 0;
}
.kind-pick-btn {
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
.kind-pick-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #1a2b22;
}
.kind-pick-chev {
  color: #5a6b62;
  font-size: 0.75rem;
}
.kind-pick-menu {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  background: #fff;
  border: 1px solid #b8c9be;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(26, 43, 34, 0.12);
  overflow: hidden;
}
.kind-pick-list {
  list-style: none;
  margin: 0;
  padding: 0.25rem 0;
}
.kind-pick-option {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border: none;
  background: transparent;
  padding: 0.4rem 0.55rem;
  font: inherit;
  cursor: pointer;
  text-align: left;
  color: #1a2b22;
}
.kind-pick-option:hover,
.kind-pick-option.on {
  background: #e8f2eb;
}
</style>
