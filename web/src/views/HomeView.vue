<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'

const router = useRouter()
const route = useRoute()
const user = ref(null)
const worlds = ref([])
const error = ref('')
const busy = ref(false)

const loginName = ref('')
const password = ref('')
const mode = ref('login')
const worldName = ref('Первый мир')
const mapDraftHint = ref('')

async function refresh() {
  error.value = ''
  const me = await api.me()
  user.value = me.user
  if (user.value) {
    const w = await api.worlds()
    worlds.value = w.worlds
  } else {
    worlds.value = []
  }
}

async function submitAuth() {
  busy.value = true
  error.value = ''
  try {
    if (mode.value === 'login') {
      await api.login(loginName.value, password.value)
    } else {
      await api.register(loginName.value, password.value)
    }
    await refresh()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function mockVk() {
  busy.value = true
  error.value = ''
  try {
    await api.vkMock(10001, 'vk-dev@example.com')
    await refresh()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function logout() {
  await api.logout()
  user.value = null
  worlds.value = []
}

async function createWorld(mapSeed) {
  busy.value = true
  error.value = ''
  try {
    const payload = { name: worldName.value }
    if (mapSeed != null) payload.map_seed = mapSeed
    const res = await api.createWorld(payload)
    await refresh()
    router.push({ name: 'world', params: { id: res.world.id } })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

function createFromMapDraft() {
  try {
    const raw = localStorage.getItem('civaris.map-puzzle.create-draft.v1')
    if (!raw) return null
    const draft = JSON.parse(raw)
    if (!Number.isInteger(draft.seed)) return null
    return draft.seed
  } catch {
    return null
  }
}

async function createWorldFromMap() {
  const seed = createFromMapDraft()
  if (seed == null) {
    error.value = 'На /map откройте карту и нажмите «Создать мир с этой картой»'
    return
  }
  await createWorld(seed)
}

onMounted(() => {
  if (route.query.fromMap === '1' && createFromMapDraft() != null) {
    mapDraftHint.value = `Черновик карты: seed ${createFromMapDraft()} — войдите и нажмите «Создать из черновика карты»`
  }
  refresh().catch((e) => {
    error.value = e.message + ' — проверь API/БД (civaris.loc/api/health)'
  })
})
</script>

<template>
  <section class="panel">
    <h1>Наблюдай цивилизации</h1>
    <p class="lead">
      Малая карта: 5 деревень × 20 человечков на стартовых провинциях. Календарь со «щелчка». Хозяйственный тик — следующий срез.
    </p>

    <p v-if="mapDraftHint" class="hint">{{ mapDraftHint }}</p>
    <p v-if="error" class="err">{{ error }}</p>

    <div v-if="!user" class="auth">
      <div class="tabs">
        <button type="button" :class="{ on: mode === 'login' }" @click="mode = 'login'">Вход</button>
        <button type="button" :class="{ on: mode === 'register' }" @click="mode = 'register'">Регистрация</button>
      </div>
      <label>
        Логин
        <input v-model="loginName" autocomplete="username" />
      </label>
      <label>
        Пароль
        <input v-model="password" type="password" autocomplete="current-password" />
      </label>
      <button type="button" class="primary" :disabled="busy" @click="submitAuth">
        {{ mode === 'login' ? 'Войти' : 'Создать аккаунт' }}
      </button>
      <button type="button" class="ghost" :disabled="busy" @click="mockVk">
        VK mock (локалка)
      </button>
    </div>

    <div v-else class="desk">
      <p>
        Вы вошли как <strong>{{ user.login }}</strong>
        <button type="button" class="ghost inline" @click="logout">Выйти</button>
      </p>

      <div class="create">
        <label>
          Имя мира
          <input v-model="worldName" />
        </label>
        <button type="button" class="primary" :disabled="busy" @click="createWorld()">
          Создать мир (малая · 5×20)
        </button>
        <button type="button" class="ghost" :disabled="busy" @click="createWorldFromMap">
          Создать из черновика карты (/map)
        </button>
      </div>

      <h2>Ваши миры</h2>
      <ul v-if="worlds.length" class="worlds">
        <li v-for="w in worlds" :key="w.id">
          <router-link :to="{ name: 'world', params: { id: w.id } }">
            {{ w.name }}
          </router-link>
          <span class="meta">
            {{ w.day }}.{{ w.month }}.{{ w.year }} · население {{ w.population }}/{{ w.population_cap }}
            <template v-if="w.map_seed"> · seed {{ w.map_seed }}</template>
          </span>
        </li>
      </ul>
      <p v-else class="muted">Миров пока нет.</p>
    </div>
  </section>
</template>

<style scoped>
.panel {
  background: rgba(255, 252, 245, 0.85);
  border: 1px solid #b7c4a4;
  border-radius: 4px;
  padding: 1.25rem 1.35rem 1.5rem;
}
h1 {
  margin: 0 0 0.35rem;
  font-size: 1.55rem;
  color: #1f3d28;
}
.lead {
  margin: 0 0 1.25rem;
  color: #4a5740;
}
.err {
  color: #8b2e2e;
  background: #f7e4e4;
  padding: 0.5rem 0.75rem;
  border-radius: 3px;
}
.auth,
.desk {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  max-width: 420px;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}
input {
  padding: 0.45rem 0.55rem;
  border: 1px solid #9aab88;
  border-radius: 3px;
  background: #fff;
}
.tabs {
  display: flex;
  gap: 0.35rem;
}
.tabs button {
  border: 1px solid #9aab88;
  background: #f3f0e6;
  padding: 0.35rem 0.7rem;
  border-radius: 3px;
}
.tabs button.on {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
}
.primary {
  border: none;
  background: #2f5d3a;
  color: #fff;
  padding: 0.55rem 0.9rem;
  border-radius: 3px;
}
.ghost {
  border: 1px dashed #7d8f6c;
  background: transparent;
  color: #2f5d3a;
  padding: 0.45rem 0.8rem;
  border-radius: 3px;
}
.ghost.inline {
  margin-left: 0.5rem;
  padding: 0.15rem 0.45rem;
  font-size: 0.85rem;
}
.create {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0.5rem 0 1rem;
}
h2 {
  margin: 0.5rem 0 0.35rem;
  font-size: 1.1rem;
}
.worlds {
  list-style: none;
  padding: 0;
  margin: 0;
}
.worlds li {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid #d5ddc8;
}
.meta {
  font-size: 0.85rem;
  color: #5a6b4e;
}
.muted {
  color: #6a7760;
}
.hint {
  color: #2f5d3a;
  background: #eef4e8;
  padding: 0.5rem 0.75rem;
  border-radius: 3px;
  margin: 0 0 0.75rem;
}
</style>
