<script setup lang="ts">
import { useRouter } from 'vue-router'
import { EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import { useAdminClientStaff } from '../../composables/admin/useAdminClientStaff'
import { useAdminRemoveClientStaff } from '../../composables/admin/useAdminRemoveClientStaff'
import { formatDate } from '@/core/utils/date'
import { getRoleLabel } from '@/core/utils/roles'
import type { ClientStaffItem } from '../../types/client.types'

const props = defineProps<{
  clientGuid: string
}>()

const router = useRouter()

const { data: staff, isLoading }                     = useAdminClientStaff(props.clientGuid)
const { removeStaff, isPending: isRemoving }         = useAdminRemoveClientStaff(props.clientGuid)

function goToEdit(member: ClientStaffItem): void {
  router.push(`/admin/clients/${props.clientGuid}/staff/${member.guid}/editar`)
}

const columns = [
  { title: 'Nombre / Email', key: 'user',       dataIndex: 'user' },
  { title: 'Rol',            key: 'role' },
  { title: 'Alta',           key: 'created_at' },
  { title: 'Acciones',       key: 'actions', width: 110 },
]
</script>

<template>
  <div class="acss-root">
    <EmptyState
      v-if="!isLoading && !staff?.length"
      message="Este cliente no tiene miembros de staff."
    />

    <BaseDataTable
      v-else
      :columns="columns"
      :data-source="staff ?? []"
      :loading="isLoading || isRemoving"
      row-key="guid"
      :scroll="{ x: 600 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'user'">
          <div class="acss-user-cell">
            <span class="acss-name">{{ record.user.name }}</span>
            <span class="acss-email">{{ record.user.email }}</span>
          </div>
        </template>

        <template v-else-if="column.key === 'role'">
          <a-tag>{{ getRoleLabel(record.role.name) }}</a-tag>
        </template>

        <template v-else-if="column.key === 'created_at'">
          {{ formatDate(record.created_at) }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="clients.staff.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="goToEdit(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="clients.staff.delete">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Eliminar"
                danger
                @click="removeStaff(record)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>
  </div>
</template>

<style scoped>
.acss-root { padding-top: 4px; }

.acss-user-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.acss-name  { font-weight: 600; color: var(--dt-title, #fff); }
.acss-email { font-family: monospace; font-size: 12px; color: var(--dt-muted, #6B8CAE); }
</style>
