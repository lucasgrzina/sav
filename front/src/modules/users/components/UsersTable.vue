<script setup lang="ts">
import { computed } from 'vue'
import {
  EditOutlined,
  LockOutlined,
  UnlockOutlined,
  KeyOutlined,
  ReloadOutlined,
  DeleteOutlined,
} from '@ant-design/icons-vue'
import UserStatusBadge from './UserStatusBadge.vue'
import type { UserItem, UserType } from '../types/user.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'
import { getRoleLabel } from '@/core/utils/roles'
import {formatDate} from '@/core/utils/date'

const props = defineProps<{
  users: UserItem[]
  loading: boolean
  columns?: TableColumnDef[]
  type?: UserType
}>()

const emit = defineEmits<{
  edit: [user: UserItem]
  delete: [user: UserItem]
  'change-password': [user: UserItem]
  'toggle-lock': [user: UserItem]
  'reset-password': [user: UserItem]
}>()

const defaultColumns: TableColumnDef[] = [
  { title: 'Nombre completo', dataIndex: 'name', key: 'name' },
  { title: 'Email', dataIndex: 'email', key: 'email' },
  { title: 'Estado', key: 'status' },
  { title: 'Último login', key: 'last_login_at' },
  { title: 'Fecha registro', key: 'created_at' },
  { title: 'Acciones', key: 'actions', width: 220, alwaysVisible: true },
]

const columns = computed(() => props.columns ?? defaultColumns)
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="users"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 900 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'roles'">
        <template v-if="props.type === 'tenant'">
          <a-tag v-for="profile in (record.profiles ?? [])" :key="profile.guid" style="margin-bottom: 2px">
            {{ profile.tenant_name }}: {{ getRoleLabel(profile.role.name) }}
          </a-tag>
          <span v-if="!record.profiles?.length" class="text-gray-400">—</span>
        </template>
        <template v-else>
          <a-tag v-for="role in (record.roles ?? [])" :key="role.guid" style="margin-bottom: 2px">
            {{ getRoleLabel(role.name) }}
          </a-tag>
          <span v-if="!record.roles?.length" class="text-gray-400">—</span>
        </template>
      </template>

      <template v-else-if="column.key === 'status'">
        <UserStatusBadge :status="record.status" />
      </template>

      <template v-else-if="column.key === 'last_login_at'">
        {{ formatDate(record.last_login_at) }}
      </template>

      <template v-else-if="column.key === 'created_at'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton variant="row-action" size="small" @click="emit('edit', record)" tooltip="Editar">
            <template #icon><EditOutlined /></template>
          </BaseButton>

          <BaseButton variant="row-action" size="small" :tooltip="record.locked_at ? 'Desbloquear' : 'Bloquear'" @click="emit('toggle-lock', record)">
            <template #icon>
              <UnlockOutlined v-if="record.locked_at" />
              <LockOutlined v-else />
            </template>
          </BaseButton>

          <BaseButton variant="row-action" size="small" @click="emit('change-password', record)" tooltip="Cambiar contraseña">
            <template #icon><KeyOutlined /></template>
          </BaseButton>

          <a-popconfirm
            title="¿Resetear la contraseña? Se generará una nueva automáticamente."
            ok-text="Confirmar"
            cancel-text="Cancelar"
            @confirm="emit('reset-password', record)"
          >
            <BaseButton variant="row-action" size="small" tooltip="Resetear contraseña">
              <template #icon><ReloadOutlined /></template>
            </BaseButton>
          </a-popconfirm>

          <BaseButton variant="row-action" size="small" danger tooltip="Eliminar" @click="emit('delete', record)">
            <template #icon><DeleteOutlined /></template>
          </BaseButton>
          
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>
