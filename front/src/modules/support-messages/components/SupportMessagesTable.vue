<script setup lang="ts">
import { EyeOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import SupportMessageStatusBadge from './SupportMessageStatusBadge.vue'
import { formatDate } from '@/core/utils/date'
import type { SupportMessage, SupportMessagePriority } from '../types/support-message.types'

defineProps<{
  messages: SupportMessage[]
  loading: boolean
  showSender?: boolean
}>()

const emit = defineEmits<{
  view: [message: SupportMessage]
  delete: [message: SupportMessage]
}>()

const columns = [
  { title: 'Asunto', key: 'subject', dataIndex: 'subject' },
  { title: 'Remitente', key: 'sender' },
  { title: 'Estado', key: 'status', width: 130 },
  { title: 'Prioridad', key: 'priority', width: 120 },
  { title: 'Fecha', key: 'created_at', width: 160 },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]

function priorityColor(priority: SupportMessagePriority): string {
  const map: Record<SupportMessagePriority, string> = {
    low: 'default',
    medium: 'gold',
    high: 'red',
  }
  return map[priority]
}

function priorityLabel(priority: SupportMessagePriority): string {
  const map: Record<SupportMessagePriority, string> = {
    low: 'Baja',
    medium: 'Media',
    high: 'Alta',
  }
  return map[priority]
}
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="messages"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 800 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'subject'">
        <a class="font-medium cursor-pointer" @click="emit('view', record)">
          {{ record.subject }}
        </a>
      </template>

      <template v-else-if="column.key === 'status'">
        <SupportMessageStatusBadge :status="record.status" />
      </template>

      <template v-else-if="column.key === 'priority'">
        <a-tag :color="priorityColor(record.priority)">{{ priorityLabel(record.priority) }}</a-tag>
      </template>

      <template v-else-if="column.key === 'sender'">
        {{ record.sender?.name ?? '—' }}
      </template>

      <template v-else-if="column.key === 'created_at'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton variant="row-action" size="small" tooltip="Ver detalle" @click="emit('view', record)">
            <template #icon><EyeOutlined /></template>
          </BaseButton>

          <PermissionGuard permission="support-messages.delete">
            <BaseButton
              v-if="record.status === 'open'"
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
