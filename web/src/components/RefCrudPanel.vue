<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '../api'

const props = defineProps({
  /** families | click-needs | knowledge | calories */
  resource: { type: String, required: true },
})

const emit = defineEmits(['error', 'info'])

const items = ref([])
const busy = ref(false)
const editId = ref(null)

const ITEM_TYPES = [
  { key: 'food', ru: 'съедобное/пища' },
  { key: 'semi', ru: 'полуфабрикат' },
  { key: 'good', ru: 'товар' },
  { key: 'item', ru: 'предмет' },
  { key: 'container', ru: 'тара' },
  { key: 'water', ru: 'вода' },
]

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

const titles = {
  families: 'Семейства',
  'click-needs': 'Потребности щелчка',
  calories: 'Карта калорий',
  knowledge: 'Ветка знаний',
}

const form = reactive({ ...forms.families })

/** calories → API resource `catalog` (ref_catalog, only food) */
const apiResource = computed(() => (props.resource === 'calories' ? 'catalog' : props.resource))

const title = computed(() => titles[props.resource] || props.resource)

const hint = computed(() => {
  if (props.resource === 'calories') {
    return 'Только «съедобное/пища» из legacy ref_catalog. Здесь задаём ккал. Товары Sheet — во вкладке «Товары».'
  }
  if (props.resource === 'families') {
    return 'Семейства мира (животные, плоды, металлы…).'
  }
  if (props.resource === 'click-needs') {
    return 'Нормы щелчка: вода, еда, энергия.'
  }
  if (props.resource === 'knowledge') {
    return 'Ветка знаний / tech tree.'
  }
  return ''
})

const visibleItems = computed(() => {
  if (props.resource === 'calories') {
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
  if (props.resource === 'families') {
    return [
      'id',
      'group_key',
      'group_name_ru',
      'item_key',
      'item_name_ru',
      'role_note',
      'sort_order',
      'is_active',
    ]
  }
  if (props.resource === 'click-needs') {
    return ['id', 'need_key', 'name_ru', 'period', 'amount', 'unit', 'how_closed', 'sort_order', 'is_active']
  }
  if (props.resource === 'calories') {
    return ['id', 'sku_key', 'name_ru', 'food_subtype_ru', 'unit', 'kcal', 'notes', 'sort_order', 'is_active']
  }
  if (props.resource === 'knowledge') {
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
  const src = forms[props.resource] || {}
  Object.keys(form).forEach((k) => delete form[k])
  Object.assign(form, JSON.parse(JSON.stringify(src)))
}

async function loadItems() {
  busy.value = true
  emit('error', '')
  try {
    const res = await api.settingsList(apiResource.value)
    items.value = res.items || []
  } catch (e) {
    emit('error', e.message)
    items.value = []
  } finally {
    busy.value = false
  }
}

function startEdit(row) {
  editId.value = row.id
  Object.keys(form).forEach((k) => delete form[k])
  Object.assign(form, JSON.parse(JSON.stringify(forms[props.resource])))
  Object.keys(form).forEach((k) => {
    if (row[k] !== undefined && row[k] !== null) form[k] = row[k]
  })
  if (props.resource === 'calories') {
    form.item_type = 'food'
    form.item_type_ru = 'съедобное/пища'
  }
}

async function saveRow() {
  busy.value = true
  emit('error', '')
  emit('info', '')
  try {
    const payload = { ...form }
    if (props.resource === 'calories') {
      payload.item_type = 'food'
      payload.item_type_ru = 'съедобное/пища'
    }
    if (editId.value) {
      await api.settingsUpdate(apiResource.value, editId.value, payload)
      emit('info', 'Сохранено')
    } else {
      await api.settingsCreate(apiResource.value, payload)
      emit('info', 'Добавлено')
    }
    resetForm()
    await loadItems()
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

async function removeRow(id) {
  if (!confirm('Удалить запись #' + id + '?')) return
  busy.value = true
  emit('error', '')
  try {
    await api.settingsDelete(apiResource.value, id)
    await loadItems()
  } catch (e) {
    emit('error', e.message)
  } finally {
    busy.value = false
  }
}

watch(
  () => props.resource,
  () => {
    emit('info', '')
    emit('error', '')
    resetForm()
    loadItems()
  }
)

onMounted(() => {
  resetForm()
  loadItems()
})
</script>

<template>
  <div class="ref-crud">
    <p v-if="hint" class="hint">{{ hint }}</p>
    <div class="grid">
      <div class="box">
        <h2>{{ editId ? `Редактировать #${editId}` : `Добавить · ${title}` }}</h2>
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
              :disabled="resource === 'calories'"
              @change="onItemTypeChange"
            >
              <option v-for="t in ITEM_TYPES" :key="t.key" :value="t.key">
                {{ t.ru }} ({{ t.key }})
              </option>
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
              v-else-if="
                typeof forms[resource]?.[key] === 'number' ||
                key === 'amount' ||
                key === 'kcal' ||
                key === 'block_num' ||
                key === 'sort_order'
              "
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
  </div>
</template>

<style scoped>
.hint {
  margin: 0 0 0.65rem;
  font-size: 0.88rem;
  color: #5a6b4e;
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
  cursor: pointer;
  font: inherit;
}
.ghost {
  border: 1px dashed #7d8f6c;
  background: transparent;
  color: #2f5d3a;
  padding: 0.35rem 0.55rem;
  border-radius: 3px;
  cursor: pointer;
  font: inherit;
}
.danger {
  border: none;
  background: #8b2e2e;
  color: #fff;
  padding: 0.2rem 0.45rem;
  border-radius: 3px;
  cursor: pointer;
  font: inherit;
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
</style>
