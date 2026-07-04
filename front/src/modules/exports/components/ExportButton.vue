<script setup lang="ts">
import { DownloadOutlined } from '@ant-design/icons-vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { useExportsUiStore } from '../stores/exports-ui.store'
import { useInitiateExport } from '../composables/useInitiateExport'
import type { ExportType } from '../types/export.types'

const props = defineProps<{
  size:             string,
  exportType:       ExportType
  filters?:         Record<string, string | undefined>
  availableColumns?: { key: string; label: string }[]
}>()

const exportsUiStore = useExportsUiStore()
const mutation       = useInitiateExport()

function handleExport() {
  exportsUiStore.openExportModal({
    exportType:       props.exportType,
    filters:          props.filters ?? {},
    availableColumns: props.availableColumns ?? [],
  })
}
</script>

<template>
  <PermissionGuard permission="exports.create">
    <BaseButton
      :size="size ?? 'default'"
      variant="tertiary"
      :loading="mutation.isPending.value"
      @click="handleExport"
    >
      <template #icon><DownloadOutlined /></template>
      Exportar
    </BaseButton>
  </PermissionGuard>
</template>
