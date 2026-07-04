<script setup lang="ts">
import { useRouter } from 'vue-router'
import { EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import { useAdminVetStaff }    from '../composables/useAdminVetStaff'
import { useAdminRemoveStaff } from '../composables/useAdminRemoveStaff'
import { formatDate }          from '@/core/utils/date'
import { getRoleLabel }        from '@/core/utils/roles'
import type { VetStaffItem } from '../types/vet.types'

const props = defineProps<{ vetGuid: string }>()

const router = useRouter()

const { data: staff, isLoading }             = useAdminVetStaff(props.vetGuid)
const { removeStaff, isPending: isRemoving } = useAdminRemoveStaff(props.vetGuid)

function goToEdit(member: VetStaffItem) {
  router.push(`/admin/vets/${props.vetGuid}/staff/${member.guid}/editar`)
}

const columns = [
  { title: 'Nombre',   key: 'name' },
  { title: 'Email',    key: 'email' },
  { title: 'Rol',      key: 'role' },
  { title: 'Alta',     key: 'created_at' },
  { title: 'Acciones', key: 'actions', width: 110 },
]
</script>

<template>
  <div class="vss-root">
    <EmptyState
      v-if="!isLoading && !staff?.length"
      message="Esta veterinaria no tiene miembros de staff."
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
        <template v-if="column.key === 'name'">
          <span class="vss-name">{{ record.user.name }}</span>
        </template>

        <template v-else-if="column.key === 'email'">
          <span class="vss-email">{{ record.user.email }}</span>
        </template>

        <template v-else-if="column.key === 'role'">
          <a-tag>{{ getRoleLabel(record.role.name) }}</a-tag>
        </template>

        <template v-else-if="column.key === 'created_at'">
          {{ formatDate(record.created_at) }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="vets.staff.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="goToEdit(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="vets.staff.delete">
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
.vss-root  { padding-top: 4px; }
.vss-name  { font-weight: 600; color: var(--dt-title, #fff); }
.vss-email { font-family: monospace; font-size: 12px; color: var(--dt-text, #C8E2EF); }
</style>
