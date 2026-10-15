<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  id: { type: [String, Number], required: true },
  settlementId: { type: [String, Number], required: false },
})

const router = useRouter()

onMounted(() => {
  router.replace({ name: 'world', params: { id: props.id } })
})
</script>

<template>
  <p class="meta">Переход в Макленд…</p>
</template>

<style scoped>
.meta {
  color: #5a6b4e;
}
</style>
