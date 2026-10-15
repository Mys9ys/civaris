<script setup>
import { computed } from 'vue'

const props = defineProps({
  personId: { type: [Number, String], default: 0 },
  name: { type: String, default: '' },
  size: { type: [Number, String], default: 28 },
  interactive: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const px = computed(() => `${Number(props.size) || 28}px`)
/** Лёгкий сдвиг оттенка, чтобы одинаковые лица отличались. */
const tint = computed(() => {
  const id = Number(props.personId) || 0
  const hue = (id * 47) % 360
  const sat = 0.85 + ((id * 13) % 20) / 100
  return `hue-rotate(${hue}deg) saturate(${sat})`
})

function onClick() {
  if (props.interactive) {
    emit('select')
  }
}
</script>

<template>
  <button
    v-if="interactive"
    type="button"
    class="cube-avatar"
    :style="{ width: px, height: px, filter: tint }"
    :title="name"
    :aria-label="name || 'житель'"
    @click.stop="onClick"
  >
    <span class="face" aria-hidden="true" />
  </button>
  <span
    v-else
    class="cube-avatar"
    :style="{ width: px, height: px, filter: tint }"
    :title="name"
    role="img"
    :aria-label="name || 'житель'"
  >
    <span class="face" aria-hidden="true" />
  </span>
</template>

<style scoped>
.cube-avatar {
  position: relative;
  display: inline-block;
  flex-shrink: 0;
  padding: 0;
  border: 1px solid #b7c4a4;
  border-radius: 50%;
  background: #dfe8d7;
  overflow: hidden;
  line-height: 0;
  cursor: default;
  vertical-align: middle;
}
button.cube-avatar {
  cursor: pointer;
}
button.cube-avatar:hover {
  border-color: #2f5d3a;
  box-shadow: 0 0 0 2px rgba(47, 93, 58, 0.18);
}
button.cube-avatar:focus-visible {
  outline: 2px solid #2f5d3a;
  outline-offset: 1px;
}
/* Кроп «как в документе»: голова + плечи. */
.face {
  position: absolute;
  inset: 0;
  background-image: url('/assets/cube-s2/cube-s2-base-front.png');
  background-repeat: no-repeat;
  background-size: 195%;
  background-position: 50% 8%;
}
</style>
