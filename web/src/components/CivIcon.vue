<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  name: { type: String, required: true },
  label: { type: String, default: '' },
  size: { type: [Number, String], default: 18 },
  mark: { type: [String, Number], default: '' },
  shop: { type: Boolean, default: false },
  tintKey: { type: String, default: '' },
  /** Если задан — пробуем soft-3D PNG /assets/icons/sku/{skuKey}.png */
  skuKey: { type: String, default: '' },
})

const imgFailed = ref(false)
watch(
  () => props.skuKey,
  () => {
    imgFailed.value = false
  }
)

const known = new Set([
  'coin', 'workers', 'vacancy', 'population', 'credit', 'firm',
  'apple', 'water', 'pot', 'clay', 'log', 'wheat', 'egg', 'milk',
  'flatbread', 'cheese', 'stone', 'crate', 'flour',
  'axe', 'hammer', 'shovel', 'knife', 'steak', 'juice',
  'rope', 'cart', 'well', 'oven', 'mill', 'wheel', 'paper', 'book',
  'paint', 'ink', 'board', 'branch', 'ration', 'banana', 'service',
  'stock',
  'kind-raw', 'kind-internal', 'kind-portion', 'kind-pack', 'kind-tool', 'kind-good', 'kind-building', 'kind-project', 'kind-service',
])

const METRICS = new Set(['coin', 'workers', 'vacancy', 'population', 'credit', 'firm', 'book'])

const icon = computed(() => (known.has(props.name) ? props.name : 'stock'))
const px = computed(() => `${Number(props.size) || 18}px`)
const aria = computed(() => props.label || undefined)
const markText = computed(() => {
  if (props.mark === null || props.mark === undefined || props.mark === '') return ''
  return String(props.mark)
})

const imgSrc = computed(() => {
  if (!props.skuKey || imgFailed.value) return ''
  return `/assets/icons/sku/${props.skuKey}.png`
})

const useImg = computed(() => !!imgSrc.value)

const glyphStyle = computed(() => {
  if (useImg.value || icon.value !== 'stock' || METRICS.has(icon.value)) return undefined
  const key = props.tintKey || ''
  if (!key) return undefined
  let h = 0
  for (let i = 0; i < key.length; i++) h = (h * 33 + key.charCodeAt(i)) % 360
  return { filter: `hue-rotate(${h}deg) saturate(1.1)` }
})

function onImgError() {
  imgFailed.value = true
}
</script>

<template>
  <span class="civ-icon" :style="{ width: px, height: px }" :aria-label="aria" :role="label ? 'img' : undefined">
    <img
      v-if="useImg"
      class="glyph-img"
      :src="imgSrc"
      alt=""
      draggable="false"
      @error="onImgError"
    />
    <span v-else class="glyph" :style="glyphStyle">
      <svg v-if="icon === 'coin'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="11" r="6.2" fill="#f0c040" stroke="#1f3d28" stroke-width="1.6" />
        <path d="M12 7.4v7.2M9.6 9.2h2.6a1.6 1.6 0 0 1 0 3.2H9.6" stroke="#1f3d28" stroke-width="1.4" stroke-linecap="round" />
      </svg>
      <svg v-else-if="icon === 'workers'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="8" y="4.2" width="8" height="7" rx="1.4" fill="#c4a574" opacity=".4" stroke="#1f3d28" stroke-width="1.75" />
        <rect x="7" y="14" width="10" height="5.4" rx="1.2" fill="#2f5d3a" opacity=".18" stroke="#1f3d28" stroke-width="1.75" />
      </svg>
      <svg v-else-if="icon === 'vacancy'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="7.5" y="4.5" width="9" height="8" rx="1.5" fill="#fffcf5" stroke="#1f3d28" stroke-width="1.75" stroke-dasharray="2.2 1.8" />
      </svg>
      <svg v-else-if="icon === 'population'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="3.5" y="9" width="5.2" height="4.6" rx="1" fill="#c4a574" opacity=".45" stroke="#1f3d28" stroke-width="1.5" />
        <rect x="9.4" y="6.5" width="5.2" height="4.6" rx="1" fill="#fffcf5" stroke="#1f3d28" stroke-width="1.5" />
        <rect x="15.3" y="9" width="5.2" height="4.6" rx="1" fill="#2f5d3a" opacity=".22" stroke="#1f3d28" stroke-width="1.5" />
      </svg>
      <svg v-else-if="icon === 'credit'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M6 18.5V8.2l6-3.4 6 3.4v10.3" stroke="#1f3d28" stroke-width="1.75" stroke-linejoin="round" />
      </svg>
      <svg v-else-if="icon === 'firm'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="4.5" y="7" width="15" height="12" rx="1.6" fill="#c4a574" opacity=".35" stroke="#1f3d28" stroke-width="1.75" />
      </svg>
      <svg v-else-if="icon === 'apple'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M12 5.2c2.5 0 5 2.7 5 6.6 0 3.9-2.1 7.2-5 7.2s-5-3.3-5-7.2c0-3.9 2.5-6.6 5-6.6z" fill="#e23d3d" stroke="#1f3d28" stroke-width="1.5" />
      </svg>
      <svg v-else-if="icon === 'axe'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M10.5 6.5l7 7" stroke="#8b5a2b" stroke-width="2.4" stroke-linecap="round" />
        <path d="M6 8.5l5-2.5 2.2 2.2-3.5 5.5c-1.8-1-3.5-2.8-3.7-5.2z" fill="#8a9099" stroke="#1f3d28" stroke-width="1.4" />
      </svg>
      <svg v-else-if="icon === 'shovel'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M12 4.5v9.5" stroke="#8b5a2b" stroke-width="2.2" stroke-linecap="round" />
        <path d="M8.5 14.5h7l-1.2 5.2a2.2 2.2 0 0 1-2.3 1.6h-.4a2.2 2.2 0 0 1-2.3-1.6L8.5 14.5z" fill="#8a9099" stroke="#1f3d28" stroke-width="1.4" />
      </svg>
      <svg v-else-if="icon === 'pot'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M7.6 8h8.8l1.1 10.2a1.3 1.3 0 0 1-1.3 1.4H7.8a1.3 1.3 0 0 1-1.3-1.4L7.6 8z" fill="#c47a4a" stroke="#1f3d28" stroke-width="1.5" />
      </svg>
      <svg v-else-if="icon === 'kind-raw'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="4.5" y="7" width="15" height="11.5" rx="1.6" fill="#c4a574" opacity=".45" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M8 11.5h8M8 15h5" stroke="#1f3d28" stroke-width="1.4" stroke-linecap="round" opacity=".55" />
      </svg>
      <svg v-else-if="icon === 'kind-internal'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="5" y="6.5" width="14" height="11" rx="1.5" fill="#2f5d3a" opacity=".18" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M9 12h6.5M13.2 9.2L16.5 12l-3.3 2.8" stroke="#1f3d28" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      <svg v-else-if="icon === 'kind-portion'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="7.2" fill="#c4a574" opacity=".28" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M12 4.8V12l5.2 5.2" stroke="#1f3d28" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M12 12h7.2" stroke="#2f5d3a" stroke-width="1.4" stroke-linecap="round" opacity=".65" />
      </svg>
      <svg v-else-if="icon === 'kind-pack'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M6.5 9.5h11l-.7 9.2a1.2 1.2 0 0 1-1.2 1.1H8.4a1.2 1.2 0 0 1-1.2-1.1L6.5 9.5z" fill="#c4a574" opacity=".4" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M9 9.5c0-1.8 1.2-3 3-3s3 1.2 3 3" stroke="#1f3d28" stroke-width="1.5" stroke-linecap="round" />
      </svg>
      <svg v-else-if="icon === 'kind-tool'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M8.5 15.5l7-7" stroke="#8b5a2b" stroke-width="2.2" stroke-linecap="round" />
        <path d="M14.2 6.2l3.6 3.6-1.4 1.4-3.6-3.6 1.4-1.4z" fill="#8a9099" stroke="#1f3d28" stroke-width="1.3" />
        <rect x="6.2" y="14.8" width="3.2" height="4.2" rx="0.8" transform="rotate(-45 6.2 14.8)" fill="#c4a574" stroke="#1f3d28" stroke-width="1.3" />
      </svg>
      <svg v-else-if="icon === 'kind-good'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M7.2 9.2h9.6l-.8 9.2a1.2 1.2 0 0 1-1.2 1.1H9.2a1.2 1.2 0 0 1-1.2-1.1L7.2 9.2z" fill="#6bbf8a" opacity=".35" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M9.2 9.2c0-2 1.3-3.4 2.8-3.4s2.8 1.4 2.8 3.4" stroke="#1f3d28" stroke-width="1.5" stroke-linecap="round" />
        <circle cx="16.6" cy="7.2" r="3.1" fill="#f0c040" stroke="#1f3d28" stroke-width="1.2" />
      </svg>
      <svg v-else-if="icon === 'kind-building'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4.5 18.5V10.2L12 5.5l7.5 4.7v8.3" stroke="#1f3d28" stroke-width="1.5" stroke-linejoin="round" />
        <path d="M4.5 10.2L12 14.5l7.5-4.3" stroke="#1f3d28" stroke-width="1.35" opacity=".7" />
        <rect x="9.2" y="14.2" width="5.6" height="4.3" rx="0.6" fill="#c4a574" opacity=".55" stroke="#1f3d28" stroke-width="1.2" />
      </svg>
      <svg v-else-if="icon === 'kind-project'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="5.5" y="4.5" width="13" height="15" rx="1.4" fill="#dceef7" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M8.5 8.5h7M8.5 11.5h7M8.5 14.5h5" stroke="#1f3d28" stroke-width="1.35" stroke-linecap="round" opacity=".55" />
        <circle cx="16.2" cy="16.8" r="3.2" fill="#6bbf8a" opacity=".45" stroke="#1f3d28" stroke-width="1.2" />
        <path d="M16.2 15.4v2.8M14.8 16.8h2.8" stroke="#1f3d28" stroke-width="1.2" stroke-linecap="round" />
      </svg>
      <svg v-else-if="icon === 'kind-service'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="5.5" y="4.5" width="13" height="15" rx="1.4" fill="#fffcf5" stroke="#1f3d28" stroke-width="1.5" />
        <path d="M8.5 9h7M8.5 12.2h7M8.5 15.4h4.5" stroke="#1f3d28" stroke-width="1.35" stroke-linecap="round" opacity=".55" />
      </svg>
      <svg v-else-if="icon === 'book'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path
          d="M6.2 5.2h9.2a1.6 1.6 0 0 1 1.6 1.6v11.2a.8.8 0 0 1-.8.8H6.8a1.6 1.6 0 0 1-1.6-1.6V6.8A1.6 1.6 0 0 1 6.8 5.2"
          fill="#c4a574"
          opacity=".4"
          stroke="#1f3d28"
          stroke-width="1.6"
          stroke-linejoin="round"
        />
        <path d="M7.2 5.4v12.8" stroke="#1f3d28" stroke-width="1.5" stroke-linecap="round" />
        <path d="M9.4 9h5.2M9.4 12h5.2M9.4 15h3.6" stroke="#1f3d28" stroke-width="1.35" stroke-linecap="round" opacity=".55" />
      </svg>
      <svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="5" y="7.5" width="14" height="11" rx="1.4" fill="#6bbf8a" stroke="#1f3d28" stroke-width="1.5" />
      </svg>
    </span>

    <span v-if="markText" class="mark" aria-hidden="true">{{ markText }}</span>
    <span v-if="shop" class="shop-badge" title="есть внутренний двойник" aria-hidden="true">
      <svg viewBox="0 0 12 12" fill="none">
        <circle cx="6" cy="6" r="5.1" fill="#f0c040" stroke="#1f3d28" stroke-width="1.1" />
        <path d="M6 3.4v5.2M4.6 4.6h1.7a1.1 1.1 0 0 1 0 2.2H4.6" stroke="#1f3d28" stroke-width="1" stroke-linecap="round" />
      </svg>
    </span>
  </span>
</template>

<style scoped>
.civ-icon {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  line-height: 0;
  vertical-align: middle;
}
.glyph,
.glyph-img {
  width: 100%;
  height: 100%;
  display: block;
}
.glyph-img {
  object-fit: contain;
  border-radius: 6px;
}
.glyph svg {
  width: 100%;
  height: 100%;
  display: block;
}
.mark {
  position: absolute;
  right: -2%;
  bottom: -4%;
  min-width: 34%;
  height: 34%;
  padding: 0 8%;
  border-radius: 30%;
  background: #1f3d28;
  color: #fffcf5;
  font: 700 0.48em/1.5 system-ui, sans-serif;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 0 0 1px #fffcf5;
  pointer-events: none;
  z-index: 1;
}
.shop-badge {
  position: absolute;
  top: -4%;
  left: -4%;
  width: 32%;
  height: 32%;
  pointer-events: none;
  z-index: 1;
}
.shop-badge svg {
  width: 100%;
  height: 100%;
  display: block;
}
</style>
