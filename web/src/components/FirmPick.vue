<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { firmLogoSrc } from '../icons/firmLogo'

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'выберите фирму…' },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const q = ref('')
const root = ref(null)

const filtered = computed(() => {
  const needle = q.value.trim().toLowerCase()
  const list = props.options || []
  if (!needle) return list
  return list.filter((code) => String(code).toLowerCase().includes(needle))
})

function toggle() {
  open.value = !open.value
  if (open.value) q.value = ''
}

function choose(code) {
  emit('update:modelValue', code)
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
  <div ref="root" class="firm-pick" :class="{ open }">
    <button type="button" class="firm-pick-btn" @click="toggle">
      <img
        v-if="firmLogoSrc(modelValue)"
        class="firm-pick-logo"
        :src="firmLogoSrc(modelValue)"
        alt=""
      />
      <span class="firm-pick-label">{{ modelValue || placeholder }}</span>
      <span class="firm-pick-chev" aria-hidden="true">▾</span>
    </button>
    <div v-if="open" class="firm-pick-menu">
      <input v-model="q" class="firm-pick-search" type="search" placeholder="поиск…" @click.stop />
      <ul class="firm-pick-list">
        <li>
          <button type="button" class="firm-pick-option" @click="choose('')">
            <span class="firm-pick-label muted">— не указано —</span>
          </button>
        </li>
        <li v-for="code in filtered" :key="code">
          <button
            type="button"
            class="firm-pick-option"
            :class="{ on: code === modelValue }"
            @click="choose(code)"
          >
            <img
              v-if="firmLogoSrc(code)"
              class="firm-pick-logo"
              :src="firmLogoSrc(code)"
              alt=""
            />
            <span>{{ code }}</span>
          </button>
        </li>
        <li v-if="!filtered.length" class="firm-pick-empty">ничего не найдено</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.firm-pick {
  position: relative;
  min-width: 0;
}
.firm-pick-btn {
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
.firm-pick-logo {
  width: 28px;
  height: 28px;
  object-fit: contain;
  border-radius: 4px;
  flex-shrink: 0;
}
.firm-pick-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #1a2b22;
}
.firm-pick-label.muted {
  color: #7a8b82;
}
.firm-pick-chev {
  color: #5a6b62;
  font-size: 0.75rem;
}
.firm-pick-menu {
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
.firm-pick-search {
  margin: 0.4rem;
  border: 1px solid #b8c9be;
  border-radius: 5px;
  padding: 0.35rem 0.45rem;
  font: inherit;
}
.firm-pick-list {
  list-style: none;
  margin: 0;
  padding: 0 0 0.35rem;
  overflow: auto;
}
.firm-pick-option {
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
.firm-pick-option:hover,
.firm-pick-option.on {
  background: #e8f2eb;
}
.firm-pick-empty {
  padding: 0.6rem 0.75rem;
  color: #7a8b82;
  font-size: 0.85rem;
}
</style>
