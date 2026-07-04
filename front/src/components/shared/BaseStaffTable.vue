<script setup lang="ts">
import { computed } from 'vue'
import {
  EditOutlined,
  LockOutlined,
  UnlockOutlined,
  DisconnectOutlined,
  DeleteOutlined,
} from '@ant-design/icons-vue'
import { formatDate } from '@/core/utils/date'
import { getRoleLabel } from '@/core/utils/roles'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'

export interface StaffRecord {
  guid: string
  user: { name: string; email: string }
  role: { name: string }
  blocked_at: string | null
  created_at: string
}

const props = withDefaults(defineProps<{
  staff: StaffRecord[]
  loading: boolean
  columns?: TableColumnDef[]
  blockedLabel?: string
  unlinkTooltip?: string
  unlinkVariant?: 'disconnect' | 'delete'
}>(), {
  blockedLabel: 'Bloqueado',
  unlinkTooltip: 'Desvincular',
  unlinkVariant: 'delete',
})

const emit = defineEmits<{
  edit:           [member: StaffRecord]
  'toggle-block': [member: StaffRecord]
  unlink:         [member: StaffRecord]
}>()

const defaultColumns: TableColumnDef[] = [
  { title: 'Nombre / Email', key: 'user',       dataIndex: 'user' },
  { title: 'Rol',            key: 'role' },
  { title: 'Estado',         key: 'status' },
  { title: 'Alta',           key: 'created_at' },
  { title: 'Acciones',       key: 'actions', width: 140, alwaysVisible: true },
]

const resolvedColumns = computed(() => props.columns ?? defaultColumns)
</script>

<template>
  <BaseDataTable
    :columns="resolvedColumns"
    :data-source="staff"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 700 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'user'">
        <div class="bst-user-cell">
          <span class="bst-name">{{ record.user.name }}</span>
          <span class="bst-email">{{ record.user.email }}</span>
        </div>
      </template>

      <template v-else-if="column.key === 'role'">
        <a-tag>{{ getRoleLabel(record.role.name) }}</a-tag>
      </template>

      <template v-else-if="column.key === 'status'">
        <a-tag v-if="record.blocked_at" color="error">{{ blockedLabel }}</a-tag>
        <a-tag v-else color="success">Activo</a-tag>
      </template>

      <template v-else-if="column.key === 'created_at'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton
            variant="row-action"
            size="small"
            tooltip="Editar perfil"
            @click="emit('edit', record)"
          >
            <template #icon><EditOutlined /></template>
          </BaseButton>

          <BaseButton
            variant="row-action"
            size="small"
            :tooltip="record.blocked_at ? 'Desbloquear acceso' : 'Bloquear acceso'"
            @click="emit('toggle-block', record)"
          >
            <template #icon>
              <UnlockOutlined v-if="record.blocked_at" />
              <LockOutlined v-else />
            </template>
          </BaseButton>

          <BaseButton
            variant="row-action"
            size="small"
            danger
            :tooltip="unlinkTooltip"
            @click="emit('unlink', record)"
          >
            <template #icon>
              <DisconnectOutlined v-if="unlinkVariant === 'disconnect'" />
              <DeleteOutlined v-else />
            </template>
          </BaseButton>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>

<style scoped>
.bst-user-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.bst-name  { font-weight: 600; color: var(--dt-title, #fff); }
.bst-email { font-family: monospace; font-size: 12px; color: var(--dt-muted, #6B8CAE); }
</style>
