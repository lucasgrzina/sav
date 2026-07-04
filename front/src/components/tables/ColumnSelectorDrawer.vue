<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { CheckCircleOutlined } from '@ant-design/icons-vue'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'

const props = defineProps<{
  columns: TableColumnDef[]
}>()

const isOpen   = defineModel<boolean>({ default: false })
const keys     = defineModel<string[]>('keys', { default: () => [] })

const localKeys = ref<string[]>([...keys.value])

watch(isOpen, (val) => {
  if (val) localKeys.value = [...keys.value]
})

const allSelected = computed(() =>
  props.columns.length > 0 && props.columns.every(c => localKeys.value.includes(c.key))
)

function toggleColumn(key: string) {
  const idx = localKeys.value.indexOf(key)
  localKeys.value = idx === -1
    ? [...localKeys.value, key]
    : localKeys.value.filter(k => k !== key)
}

function toggleAll() {
  localKeys.value = allSelected.value ? [] : props.columns.map(c => c.key)
}

function handleSave() {
  keys.value   = [...localKeys.value]
  isOpen.value = false
}

function handleClose() {
  isOpen.value = false
}
</script>

<template>
  <BaseDrawer
    v-model="isOpen"
    title="Configuración de columnas"
    :width="440"
    @close="handleClose"
  >
    <p class="csd-hint">Seleccioná las columnas que querés visualizar en la tabla.</p>

    <div class="csd-list">
      <div
        v-for="col in columns"
        :key="col.key"
        class="csd-row"
      >
        <span class="csd-label">{{ col.title }}</span>
        <a-switch
          :checked="localKeys.includes(col.key)"
          @change="toggleColumn(col.key)"
        />
      </div>
    </div>

    <template #footer>
      <div class="csd-footer">
        <button class="csd-select-all" @click="toggleAll">
          <CheckCircleOutlined />
          {{ allSelected ? 'Deseleccionar todas' : 'Seleccionar todas' }}
        </button>
        <div class="csd-actions">
          <a-button @click="handleClose">Cancelar</a-button>
          <a-button type="primary" @click="handleSave">Guardar</a-button>
        </div>
      </div>
    </template>
  </BaseDrawer>
</template>

<style scoped>
.csd-hint {
  font-size: 13px;
  color: #6b7280;
  margin-bottom: 16px;
}

.csd-list {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
  max-height: 520px;
  overflow-y: auto;
}

.csd-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid #f3f4f6;
  background: #fff;
  transition: background 0.15s;
}

.csd-row:last-child {
  border-bottom: none;
}

.csd-row:hover {
  background: #f9fafb;
}

.csd-label {
  font-size: 14px;
  color: #374151;
}

.csd-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.csd-actions {
  display: flex;
  gap: 8px;
}

.csd-select-all {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #16a34a;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  font-weight: 500;
  transition: color 0.15s;
}

.csd-select-all:hover {
  color: #15803d;
}
</style>
