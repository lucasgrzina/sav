<script setup lang="ts">
import { computed } from 'vue'
import { EditOutlined, DeleteOutlined, SafetyCertificateOutlined } from '@ant-design/icons-vue'
import type { RoleItem } from '../types/role.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'
import { formatDate } from '@/core/utils/date'
import { getRoleLabel } from '@/core/utils/roles'

const props = defineProps<{
  roles: RoleItem[]
  loading: boolean
  columns?: TableColumnDef[]
}>()

const emit = defineEmits<{
  edit: [role: RoleItem]
  delete: [role: RoleItem]
}>()

const defaultColumns: TableColumnDef[] = [
  { title: 'Nombre', key: 'name' },
  { title: 'Tipo', key: 'type', width: 110 },
  /*{ title: 'Permisos', key: 'permissions' },*/
  { title: 'Creado', key: 'created_at' },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]

const columns = computed(() => props.columns ?? defaultColumns)

function permLabel(name: string): string {
  return name.split('.')[1] ?? name
}
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="roles"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 800 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'name'">
        <div class="rl-name-cell">
          <div class="rl-icon-wrap">
            <SafetyCertificateOutlined style="font-size:14px; color:#1AE5A0" />
          </div>
          <span class="rl-name">{{ getRoleLabel(record.name) }}</span>
        </div>
      </template>

      <template v-else-if="column.key === 'type'">
        <span
          class="rl-type-badge"
          :class="record.type === 'platform' ? 'rl-type-platform' : 'rl-type-tenant'"
        >
          {{ record.type === 'platform' ? 'Plataforma' : 'Tenant' }}
        </span>
      </template>

      <!--template v-else-if="column.key === 'permissions'">
        <div class="rl-perms">
          <span
            v-for="perm in record.permissions.slice(0, 4)"
            :key="perm.guid"
            class="rl-perm-badge"
          >{{ permLabel(perm.name) }}</span>
          <span
            v-if="record.permissions.length > 4"
            class="rl-perm-badge rl-perm-more"
          >+{{ record.permissions.length - 4 }}</span>
          <span v-if="!record.permissions.length" class="rl-no-perms">Sin permisos</span>
        </div>
      </template-->

      <template v-else-if="column.key === 'created_at'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton variant="row-action" size="small" tooltip="Editar" @click="emit('edit', record)">
            <template #icon><EditOutlined /></template>
          </BaseButton>
          <BaseButton
            variant="row-action"
            size="small"
            danger
            :tooltip="record.type === 'tenant' ? 'Los roles de tenant no pueden eliminarse' : 'Eliminar'"
            :disabled="['super-admin', 'admin'].includes(record.name) || record.type === 'tenant'"
            @click="emit('delete', record)"
          >
            <template #icon><DeleteOutlined /></template>
          </BaseButton>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>

<style scoped>
.rl-name-cell { display: flex; align-items: center; gap: 10px; }
.rl-icon-wrap {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: rgba(26,229,160,0.1);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.rl-name { font-weight: 600; }

.rl-perms { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; }
.rl-perm-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 6px;
  background: rgba(26,229,160,0.1);
  color: var(--dt-accent, #1AE5A0);
  text-transform: capitalize;
}
.rl-perm-more {
  background: rgba(107,140,174,0.15);
  color: var(--dt-muted, #6B8CAE);
}
.rl-no-perms { font-size: 12px; color: var(--dt-muted, #6B8CAE); font-style: italic; }

.rl-type-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 6px;
  text-transform: capitalize;
}
.rl-type-platform {
  background: rgba(26,229,160,0.1);
  color: var(--dt-accent, #1AE5A0);
}
.rl-type-tenant {
  background: rgba(107,140,174,0.15);
  color: var(--dt-muted, #6B8CAE);
}
</style>
