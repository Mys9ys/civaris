<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'

const router = useRouter()
const user = ref(null)
const error = ref('')
const info = ref('')
const busy = ref(false)

const passwordForm = reactive({
  current_password: '',
  new_password: '',
})

async function loadUser() {
  const me = await api.me()
  user.value = me.user
  if (!user.value) {
    router.push('/')
    return
  }
  if (user.value.role !== 'admin') {
    error.value = 'Доступ только для администратора'
  }
}

async function changePassword() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    await api.changePassword(passwordForm.current_password, passwordForm.new_password)
    passwordForm.current_password = ''
    passwordForm.new_password = ''
    info.value = 'Пароль обновлён'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await loadUser()
  } catch (e) {
    error.value = e.message
  }
})
</script>

<template>
  <section class="panel">
    <div class="head">
      <div>
        <router-link class="back" to="/">← на главную</router-link>
        <h1>Settings</h1>
        <p class="lead">
          Только аккаунт. Справочники и каталог —
          <router-link to="/catalog">/catalog</router-link>
          (вкладки: товары, постройки, семейства, потребности, знания, калории…).
        </p>
      </div>
      <p v-if="user" class="who">{{ user.login }} · {{ user.role }}</p>
    </div>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="info" class="ok">{{ info }}</p>

    <div v-if="user?.role === 'admin'" class="box">
      <h2>Смена пароля</h2>
      <label>
        Текущий пароль
        <input v-model="passwordForm.current_password" type="password" />
      </label>
      <label>
        Новый пароль
        <input v-model="passwordForm.new_password" type="password" />
      </label>
      <button type="button" class="primary" :disabled="busy" @click="changePassword">
        Сохранить пароль
      </button>
    </div>
  </section>
</template>

<style scoped>
.panel {
  background: rgba(255, 252, 245, 0.9);
  border: 1px solid #b7c4a4;
  border-radius: 4px;
  padding: 1.1rem 1.2rem 1.4rem;
}
.head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}
.back {
  color: #2f5d3a;
  font-size: 0.9rem;
}
h1 {
  margin: 0.2rem 0;
  font-size: 1.4rem;
  color: #1f3d28;
}
.lead {
  margin: 0;
  color: #5a6b4e;
  font-size: 0.92rem;
}
.who {
  font-size: 0.85rem;
  color: #5a6b4e;
}
.err {
  background: #f7e4e4;
  color: #8b2e2e;
  padding: 0.45rem 0.65rem;
  border-radius: 3px;
}
.ok {
  background: #e4f0e2;
  color: #2f5d3a;
  padding: 0.45rem 0.65rem;
  border-radius: 3px;
}
.box {
  border: 1px solid #c5d0b5;
  background: #f7f4ea;
  border-radius: 3px;
  padding: 0.85rem;
  max-width: 420px;
}
h2 {
  margin: 0 0 0.65rem;
  font-size: 1rem;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin-bottom: 0.55rem;
  font-size: 0.85rem;
}
input {
  font: inherit;
  padding: 0.35rem 0.45rem;
  border: 1px solid #b7c4a4;
  border-radius: 3px;
}
.primary {
  font: inherit;
  cursor: pointer;
  background: #2f5d3a;
  color: #fff;
  border: 1px solid #2f5d3a;
  border-radius: 3px;
  padding: 0.4rem 0.75rem;
}
</style>
