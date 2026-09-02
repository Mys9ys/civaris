<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api'

const router = useRouter()
const user = ref(null)
const error = ref('')
const info = ref('')
const tab = ref('families')
const items = ref([])
const busy = ref(false)

const tabs = [
  { key: 'families', title: 'Семейства' },
  { key: 'click-needs', title: 'Потребности щелчка' },
  { key: 'catalog', title: 'Каталог' },
  { key: 'calories', title: 'Карта калорий' },
  { key: 'knowledge', title: 'Ветка знаний' },
  { key: 'account', title: 'Аккаунт' },
]

/** Тип позиции каталога: food = съедобное/пища → попадает в карту калорий */
const ITEM_TYPES = [
  { key: 'food', ru: 'съедобное/пища' },
  { key: 'semi', ru: 'полуфабрикат' },
  { key: 'good', ru: 'товар' },
  { key: 'item', ru: 'предмет' },
  { key: 'container', ru: 'тара' },
  { key: 'water', ru: 'вода' },
]

/** Подтип еды */
const FOOD_SUBTYPES = [
  { key: 'fruit', ru: 'фрукты', category: 'fruit', category_ru: 'Фрукты' },
  { key: 'vegetable', ru: 'овощи (сырые)', category: 'vegetable', category_ru: 'Овощи сырые' },
  { key: 'vegetable_cook', ru: 'овощи (готовка)', category: 'vegetable_cook', category_ru: 'Овощи готовка' },
  { key: 'bakery', ru: 'выпечка', category: 'bakery', category_ru: 'Выпечка' },
]

const forms = {
  families: {
    group_key: '',
    group_name_ru: '',
    item_key: '',
    item_name_ru: '',
    role_note: '',
    sort_order: 0,
    is_active: 1,
  },
  'click-needs': {
    need_key: '',
    name_ru: '',
    period: 'day',
    amount: null,
    unit: '',
    how_closed: '',
    notes: '',
    sort_order: 0,
    is_active: 1,
  },
  catalog: {
    sku_key: '',
    name_ru: '',
    item_type: 'item',
    item_type_ru: 'предмет',
    food_subtype: null,
    food_subtype_ru: '',
    category: 'other',
    category_ru: '',
    unit: 'шт',
    notes: '',
    sort_order: 0,
    is_active: 1,
  },
  calories: {
    sku_key: '',
    name_ru: '',
    item_type: 'food',
    item_type_ru: 'съедобное/пища',
    food_subtype: 'fruit',
    food_subtype_ru: 'фрукты',
    category: 'fruit',
    category_ru: 'Фрукты',
    unit: 'шт',
    kcal: null,
    notes: '',
    sort_order: 0,
    is_active: 1,
  },
  knowledge: {
    block_num: 0,
    block_key: '',
    block_name_ru: '',
    knowledge_key: '',
    name_ru: '',
    requires_text: '',
    unlocks_text: '',
    sort_order: 0,
    at_click: 0,
    is_active: 1,
  },
}

const form = reactive({ ...forms.families })
const editId = ref(null)

const passwordForm = reactive({
  current_password: '',
  new_password: '',
})

/** API resource for current tab (calories → catalog) */
const apiResource = computed(() => (tab.value === 'calories' ? 'catalog' : tab.value))

const visibleItems = computed(() => {
  if (tab.value === 'calories') {
    return items.value
      .filter((row) => row.item_type === 'food')
      .slice()
      .sort((a, b) => {
        const sa = a.food_subtype || ''
        const sb = b.food_subtype || ''
        if (sa !== sb) return sa.localeCompare(sb)
        return (a.sort_order || 0) - (b.sort_order || 0)
      })
  }
  return items.value
})

const columns = computed(() => {
  if (tab.value === 'families') {
    return ['id', 'group_key', 'group_name_ru', 'item_key', 'item_name_ru', 'role_note', 'sort_order', 'is_active']
  }
  if (tab.value === 'click-needs') {
    return ['id', 'need_key', 'name_ru', 'period', 'amount', 'unit', 'how_closed', 'sort_order', 'is_active']
  }
  if (tab.value === 'catalog') {
    return ['id', 'sku_key', 'name_ru', 'item_type_ru', 'food_subtype_ru', 'unit', 'notes', 'sort_order', 'is_active']
  }
  if (tab.value === 'calories') {
    return ['id', 'sku_key', 'name_ru', 'food_subtype_ru', 'unit', 'kcal', 'notes', 'sort_order', 'is_active']
  }
  if (tab.value === 'knowledge') {
    return ['id', 'block_num', 'block_key', 'knowledge_key', 'name_ru', 'at_click', 'sort_order', 'is_active']
  }
  return []
})

function onItemTypeChange() {
  const found = ITEM_TYPES.find((t) => t.key === form.item_type)
  if (found) form.item_type_ru = found.ru
  if (form.item_type !== 'food') {
    form.food_subtype = null
    form.food_subtype_ru = ''
  } else if (!form.food_subtype) {
    onFoodSubtypeChange('fruit')
  }
}

function onFoodSubtypeChange(forcedKey) {
  const key = forcedKey || form.food_subtype
  const found = FOOD_SUBTYPES.find((t) => t.key === key)
  if (!found) return
  form.food_subtype = found.key
  form.food_subtype_ru = found.ru
  form.category = found.category
  form.category_ru = found.category_ru
}

function resetForm() {
  editId.value = null
  const src = forms[tab.value] || {}
  Object.keys(form).forEach((k) => delete form[k])
  Object.assign(form, JSON.parse(JSON.stringify(src)))
}

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

async function loadItems() {
  if (tab.value === 'account') return
  busy.value = true
  error.value = ''
  try {
    const res = await api.settingsList(apiResource.value)
    items.value = res.items || []
  } catch (e) {
    error.value = e.message
    items.value = []
  } finally {
    busy.value = false
  }
}

function startEdit(row) {
  editId.value = row.id
  Object.keys(form).forEach((k) => delete form[k])
  Object.assign(form, JSON.parse(JSON.stringify(forms[tab.value])))
  Object.keys(form).forEach((k) => {
    if (row[k] !== undefined && row[k] !== null) form[k] = row[k]
  })
  if (tab.value === 'calories') {
    form.item_type = 'food'
    form.item_type_ru = 'съедобное/пища'
  }
}

async function saveRow() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    const payload = { ...form }
    if (tab.value === 'calories') {
      payload.item_type = 'food'
      payload.item_type_ru = 'съедобное/пища'
    }
    if (editId.value) {
      await api.settingsUpdate(apiResource.value, editId.value, payload)
      info.value = 'Сохранено'
    } else {
      await api.settingsCreate(apiResource.value, payload)
      info.value = 'Добавлено'
    }
    resetForm()
    await loadItems()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function removeRow(id) {
  if (!confirm('Удалить запись #' + id + '?')) return
  busy.value = true
  error.value = ''
  try {
    await api.settingsDelete(apiResource.value, id)
    await loadItems()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function changePassword() {
  busy.value = true
  error.value = ''
  info.value = ''
  try {
    await api.changePassword(passwordForm.current_password, passwordForm.new_password)
    info.value = 'Пароль изменён'
    passwordForm.current_password = ''
    passwordForm.new_password = ''
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

watch(tab, () => {
  info.value = ''
  error.value = ''
  resetForm()
  loadItems()
})

onMounted(async () => {
  try {
    await loadUser()
    resetForm()
    await loadItems()
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
        <p class="lead">Каталог — позиции без ккал. Калории правятся во вкладке «Карта калорий».</p>
      </div>
      <p v-if="user" class="who">{{ user.login }} · {{ user.role }}</p>
    </div>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="info" class="ok">{{ info }}</p>

    <div v-if="user?.role === 'admin'" class="tabs">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        :class="{ on: tab === t.key }"
        @click="tab = t.key"
      >
        {{ t.title }}
      </button>
    </div>

    <div v-if="user?.role === 'admin' && tab === 'account'" class="box">
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
      <p class="hint">Сейчас для id=1 временный пароль <code>123456</code> — смени, когда удобно.</p>
    </div>

    <div v-else-if="user?.role === 'admin'" class="grid">
      <div class="box">
        <h2>{{ editId ? 'Редактировать #' + editId : 'Добавить' }}</h2>
        <div class="fields">
          <label v-for="(val, key) in form" :key="key">
            {{ key }}
            <select v-if="key === 'period'" v-model="form[key]">
              <option value="day">day</option>
              <option value="week">week</option>
              <option value="once">once</option>
              <option value="other">other</option>
            </select>
            <select
              v-else-if="key === 'item_type'"
              v-model="form[key]"
              :disabled="tab === 'calories'"
              @change="onItemTypeChange"
            >
              <option v-for="t in ITEM_TYPES" :key="t.key" :value="t.key">{{ t.ru }} ({{ t.key }})</option>
            </select>
            <select
              v-else-if="key === 'food_subtype'"
              v-model="form[key]"
              :disabled="form.item_type && form.item_type !== 'food'"
              @change="onFoodSubtypeChange()"
            >
              <option :value="null">—</option>
              <option v-for="t in FOOD_SUBTYPES" :key="t.key" :value="t.key">{{ t.ru }}</option>
            </select>
            <input
              v-else-if="key === 'is_active' || key === 'at_click'"
              v-model.number="form[key]"
              type="number"
              min="0"
              max="1"
            />
            <input
              v-else-if="typeof forms[tab]?.[key] === 'number' || key === 'amount' || key === 'kcal' || key === 'block_num' || key === 'sort_order'"
              v-model.number="form[key]"
              type="number"
              step="any"
            />
            <input
              v-else-if="key === 'item_type_ru' || key === 'food_subtype_ru'"
              v-model="form[key]"
              type="text"
              readonly
            />
            <input v-else v-model="form[key]" type="text" />
          </label>
        </div>
        <div class="actions">
          <button type="button" class="primary" :disabled="busy" @click="saveRow">
            {{ editId ? 'Сохранить' : 'Добавить' }}
          </button>
          <button v-if="editId" type="button" class="ghost" @click="resetForm">Сброс</button>
        </div>
      </div>

      <div class="box wide">
        <h2>Записи ({{ visibleItems.length }})</h2>
        <p v-if="tab === 'catalog'" class="hint">Без колонки ккал — её смотри/правь в «Карте калорий».</p>
        <p v-else-if="tab === 'calories'" class="hint">Только «съедобное/пища». Здесь задаём ккал.</p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th v-for="c in columns" :key="c">{{ c }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in visibleItems" :key="row.id">
                <td v-for="c in columns" :key="c">{{ row[c] }}</td>
                <td class="row-actions">
                  <button type="button" class="ghost" @click="startEdit(row)">изм.</button>
                  <button type="button" class="danger" @click="removeRow(row.id)">×</button>
                </td>
              </tr>
              <tr v-if="!visibleItems.length">
                <td :colspan="columns.length + 1" class="empty">Пока пусто — добавь первую строку слева.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
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
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0.75rem 0 1rem;
}
.tabs button {
  border: 1px solid #9aab88;
  background: #f3f0e6;
  padding: 0.35rem 0.65rem;
  border-radius: 3px;
}
.tabs button.on {
  background: #2f5d3a;
  color: #fff;
  border-color: #2f5d3a;
}
.grid {
  display: grid;
  grid-template-columns: minmax(260px, 340px) 1fr;
  gap: 0.75rem;
}
@media (max-width: 900px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
.box {
  border: 1px solid #c5d0b5;
  background: #f7f4ea;
  border-radius: 3px;
  padding: 0.75rem;
}
.box.wide {
  overflow: auto;
}
h2 {
  margin: 0 0 0.6rem;
  font-size: 1rem;
}
.fields {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.78rem;
  color: #4a5740;
}
input,
select {
  font: inherit;
  padding: 0.35rem 0.45rem;
  border: 1px solid #9aab88;
  border-radius: 3px;
  background: #fff;
}
.actions {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.65rem;
}
.primary {
  border: none;
  background: #2f5d3a;
  color: #fff;
  padding: 0.45rem 0.8rem;
  border-radius: 3px;
}
.ghost {
  border: 1px dashed #7d8f6c;
  background: transparent;
  color: #2f5d3a;
  padding: 0.35rem 0.55rem;
  border-radius: 3px;
}
.danger {
  border: none;
  background: #8b2e2e;
  color: #fff;
  padding: 0.2rem 0.45rem;
  border-radius: 3px;
}
.table-wrap {
  overflow: auto;
  max-height: 60vh;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #d5ddc8;
  padding: 0.3rem 0.35rem;
  text-align: left;
  vertical-align: top;
}
th {
  position: sticky;
  top: 0;
  background: #eef2e4;
}
.row-actions {
  white-space: nowrap;
}
.empty {
  color: #6a7760;
  padding: 0.75rem !important;
}
.hint {
  margin-top: 0.75rem;
  font-size: 0.85rem;
  color: #5a6b4e;
}
</style>
