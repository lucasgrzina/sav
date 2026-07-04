import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'

export const useExportsUiStore = defineStore('exports-ui', () => {
  // Estado del modal de exportación (tipo, columnas disponibles, filtros activos)
  const isModalOpen       = shallowRef(false)
  const pendingExportType = shallowRef<string | null>(null)
  const pendingFilters    = ref<Record<string, string | undefined>>({})
  const availableColumns  = ref<{ key: string; label: string }[]>([])
  const selectedColumns   = ref<string[]>([])

  function openExportModal(options: {
    exportType:       string
    filters:          Record<string, string | undefined>
    availableColumns: { key: string; label: string }[]
  }) {
    pendingExportType.value = options.exportType
    pendingFilters.value    = options.filters
    availableColumns.value  = options.availableColumns
    selectedColumns.value   = options.availableColumns.map(c => c.key) // todas por defecto
    isModalOpen.value       = true
  }

  function closeExportModal() {
    isModalOpen.value = false
  }

  return {
    isModalOpen,
    pendingExportType,
    pendingFilters,
    availableColumns,
    selectedColumns,
    openExportModal,
    closeExportModal,
  }
})
