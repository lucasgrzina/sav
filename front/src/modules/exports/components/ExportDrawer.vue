<script setup lang="ts">
import { computed, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { CheckCircleOutlined } from '@ant-design/icons-vue'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import { useExportsUiStore } from '../stores/exports-ui.store'
import { useInitiateExport } from '../composables/useInitiateExport'
import type { ExportFormat } from '../types/export.types'

const isOpen = defineModel<boolean>({ default: false })

const exportsUiStore                       = useExportsUiStore()
const { selectedColumns, pendingExportType, pendingFilters } = storeToRefs(exportsUiStore)
const mutation                             = useInitiateExport()
const selectedFormat                       = ref<ExportFormat | null>(null)

const formatOptions = [
  { value: 'xlsx', label: 'Excel (.xlsx)' },
  { value: 'csv',  label: 'CSV (.csv)'    },
  { value: 'txt',  label: 'Texto (.txt)'  },
  { value: 'pdf',  label: 'PDF (.pdf)'    },
]

const allSelected = computed(() =>
  exportsUiStore.availableColumns.length > 0 &&
  exportsUiStore.availableColumns.every(c => selectedColumns.value.includes(c.key))
)

function toggleColumn(key: string) {
  const idx = selectedColumns.value.indexOf(key)
  if (idx === -1) {
    selectedColumns.value = [...selectedColumns.value, key]
  } else {
    selectedColumns.value = selectedColumns.value.filter(k => k !== key)
  }
}

function toggleAll() {
  selectedColumns.value = allSelected.value
    ? []
    : exportsUiStore.availableColumns.map(c => c.key)
}

async function handleExport() {
  if (!selectedFormat.value || !pendingExportType.value) return

  await mutation.mutateAsync({
    type:    pendingExportType.value as any,
    format:  selectedFormat.value,
    async:   false,
    filters: pendingFilters.value,
    columns: selectedColumns.value,
  })

  handleClose()
}

function handleClose() {
  isOpen.value        = false
  selectedFormat.value = null
}
</script>

<template>
  <BaseDrawer
    v-model="isOpen"
    title="Exportar datos"
    :width="480"
    @close="handleClose"
  >
    <!-- Formato -->
    <div class="ed-section">
      <p class="ed-section-label">Formato de exportación</p>
      <BaseSelect
        v-model="selectedFormat"
        :options="formatOptions"
        placeholder="Seleccioná un formato"
      />
    </div>

    <a-divider class="ed-divider" />

    <!-- Columnas -->
    <div class="ed-section">
      <p class="ed-section-label">Configuración de columnas</p>
      <p class="ed-section-hint">Seleccione las columnas que desee incluir en la exportación.</p>

      <div class="ed-columns-list">
        <div
          v-for="col in exportsUiStore.availableColumns"
          :key="col.key"
          class="ed-col-row"
        >
          <span class="ed-col-name">{{ col.label }}</span>
          <a-switch
            :checked="selectedColumns.includes(col.key)"
            @change="toggleColumn(col.key)"
          />
        </div>
      </div>
    </div>

    <template #footer>
      <div class="ed-footer">
        <button class="ed-select-all" @click="toggleAll">
          <CheckCircleOutlined />
          {{ allSelected ? 'Deseleccionar todas' : 'Seleccionar todas' }}
        </button>
        <div class="ed-footer-actions">
          <BaseButton variant="secondary" @click="handleClose">Cancelar</BaseButton>
          <BaseButton
            variant="primary"
            :loading="mutation.isPending.value"
            :disabled="!selectedFormat || selectedColumns.length === 0"
            @click="handleExport"
          >
            Exportar
          </BaseButton>
        </div>
      </div>
    </template>
  </BaseDrawer>
</template>

<style scoped>
.ed-section {
  margin-bottom: 4px;
}

.ed-section-label {
  font-size: 13px;
  font-weight: 600;
  color: #1a1a1a;
  margin-bottom: 8px;
}

.ed-section-hint {
  font-size: 13px;
  color: #6b7280;
  margin-bottom: 12px;
}

.ed-divider {
  margin: 20px 0;
}

.ed-columns-list {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
  max-height: 420px;
  overflow-y: auto;
}

.ed-col-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid #f3f4f6;
  background: #fff;
  transition: background 0.15s;
}

.ed-col-row:last-child {
  border-bottom: none;
}

.ed-col-row:hover {
  background: #f9fafb;
}

.ed-col-name {
  font-size: 14px;
  color: #374151;
}

.ed-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.ed-footer-actions {
  display: flex;
  gap: 8px;
}

.ed-select-all {
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

.ed-select-all:hover {
  color: #15803d;
}
</style>
