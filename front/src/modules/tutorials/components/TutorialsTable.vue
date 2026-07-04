<script setup lang="ts">
import { EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import type { Tutorial, TutorialSource } from '../types/tutorial.types'

defineProps<{
  tutorials: Tutorial[]
  loading: boolean
}>()

const emit = defineEmits<{
  edit: [tutorial: Tutorial]
  delete: [tutorial: Tutorial]
}>()

const columns = [
  { title: 'Orden', key: 'order', dataIndex: 'order', width: 90 },
  { title: 'Título', key: 'title', dataIndex: 'title' },
  { title: 'Fuente', key: 'source', width: 120 },
  { title: 'Código', key: 'code', dataIndex: 'code', width: 180 },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]

function sourceColor(source: TutorialSource): string {
  const map: Record<TutorialSource, string> = {
    youtube: 'red',
    vimeo: 'blue',
  }
  return map[source]
}

function sourceLabel(source: TutorialSource): string {
  const map: Record<TutorialSource, string> = {
    youtube: 'YouTube',
    vimeo: 'Vimeo',
  }
  return map[source]
}
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="tutorials"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 700 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'source'">
        <a-tag :color="sourceColor(record.source)">{{ sourceLabel(record.source) }}</a-tag>
      </template>

      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <PermissionGuard permission="tutorials.update">
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Editar"
              @click="emit('edit', record)"
            >
              <template #icon><EditOutlined /></template>
            </BaseButton>
          </PermissionGuard>

          <PermissionGuard permission="tutorials.delete">
            <BaseButton
              variant="row-action"
              size="small"
              danger
              tooltip="Eliminar"
              @click="emit('delete', record)"
            >
              <template #icon><DeleteOutlined /></template>
            </BaseButton>
          </PermissionGuard>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>
