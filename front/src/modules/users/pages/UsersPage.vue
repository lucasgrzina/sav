<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { PlusOutlined, DownOutlined } from '@ant-design/icons-vue'
import UserFilters from '../components/UserFilters.vue'
import UsersTable from '../components/UsersTable.vue'
import CreateUserModal from '../components/modals/CreateUserModal.vue'
import CreateTenantUserModal from '../components/modals/CreateTenantUserModal.vue'
import EditUserModal from '../components/modals/EditUserModal.vue'
import ChangePasswordModal from '../components/modals/ChangePasswordModal.vue'
import ResetPasswordModal from '../components/modals/ResetPasswordModal.vue'
import ExportButton from '@/modules/exports/components/ExportButton.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ColumnSelectorButton from '@/components/tables/ColumnSelectorButton.vue'
import ColumnSelectorDrawer from '@/components/tables/ColumnSelectorDrawer.vue'
import { useUsers } from '../composables/useUsers'
import { useToggleLock } from '../composables/useToggleLock'
import { useUsersUiStore } from '../stores/users.store'
import { useUserFilters } from '../composables/useUserFilters'
import { useResetPassword } from '../composables/useResetPassword'
import type { UserFilters as UserFiltersType, UserType } from '../types/user.types'
import { USER_EXPORT_COLUMNS } from '../types/user.enums'
import { useDeleteUser } from '../composables/useDeleteUser'
import { useColumnVisibility } from '@/core/composables/useColumnVisibility'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'
import { useRoles } from '@/modules/roles/composables/useRoles'
import { getRoleLabel } from '@/core/utils/roles'
import TenantRoleSelect from '@/modules/roles/components/TenantRoleSelect.vue'

const USER_TABLE_COLUMNS: TableColumnDef[] = [
  { key: 'name',          title: 'Nombre completo', dataIndex: 'name' },
  { key: 'email',         title: 'Email',           dataIndex: 'email' },
  { key: 'roles',         title: 'Roles' },
  { key: 'status',        title: 'Estado' },
  { key: 'last_login_at', title: 'Último login' },
  { key: 'created_at',    title: 'Fecha registro' },
  { key: 'actions',       title: 'Acciones', width: 220, alwaysVisible: true },
]

const { visibleKeys, visibleColumns, selectableColumns, isDrawerOpen } =
  useColumnVisibility('users-table', USER_TABLE_COLUMNS)

const usersUiStore = useUsersUiStore()
const { filters, debouncedSearch } = useUserFilters()
const { toggleLockUser } = useToggleLock()
const { newPassword, showResult: showResetResult, resetPassword } = useResetPassword()
const { deleteUser } = useDeleteUser()

// --- Tabs ---
const activeTab = ref<UserType>('platform')
const platformRoleGuid = ref<string | undefined>(undefined)
const tenantRoleGuid   = ref<string | null>(null)

watch(activeTab, () => { filters.page = 1 })

const filtersModel = computed<UserFiltersType>({
  get: () => filters,
  set: (val) => Object.assign(filters, val),
})

const queryParams = computed(() => ({
  ...filters,
  search:    debouncedSearch.value,
  type:      activeTab.value,
  role_guid: activeTab.value === 'platform' ? platformRoleGuid.value : tenantRoleGuid.value,
}))

const { data, isLoading } = useUsers(queryParams)

// Roles de plataforma para el filtro del tab "platform" (Vue Query, siguen igual)
const { data: rolesData } = useRoles({ per_page: 200 })

const platformRoleOptions = computed(() =>
  rolesData.value?.data
    .filter(r => r.type === 'platform')
    .map(r => ({ value: r.guid, label: getRoleLabel(r.name) })) ?? []
)


const exportFilters = computed<Record<string, string | undefined>>(() => ({
  search:    filtersModel.value.search      || undefined,
  status:    filtersModel.value.status      || undefined,
  date_from: filtersModel.value.date_from   || undefined,
  date_to:   filtersModel.value.date_to     || undefined,
}))

const showCreate = computed({
  get: () => usersUiStore.activeModal === 'create',
  set: (v) => { if (!v) usersUiStore.closeModal() },
})
const showCreateTenant = computed({
  get: () => usersUiStore.activeModal === 'createTenant',
  set: (v) => { if (!v) usersUiStore.closeModal() },
})
const showEdit = computed({
  get: () => usersUiStore.activeModal === 'edit',
  set: (v) => { if (!v) usersUiStore.closeModal() },
})
const showChangePassword = computed({
  get: () => usersUiStore.activeModal === 'changePassword',
  set: (v) => { if (!v) usersUiStore.closeModal() },
})
</script>

<template>
  <div>
    <AppHeader title="Usuarios" subtitle="Administración general">
      <template #actions="{ buttonSize }">
        <ExportButton
          :size="buttonSize"
          export-type="users"
          :filters="exportFilters"
          :available-columns="USER_EXPORT_COLUMNS"
        />
        <a-dropdown>
          <BaseButton variant="primary" :size="buttonSize">
            <PlusOutlined />
            Nuevo usuario
            <DownOutlined />
          </BaseButton>
          <template #overlay>
            <a-menu>
              <a-menu-item key="platform" @click="usersUiStore.openModal('create')">
                Usuario de plataforma
              </a-menu-item>
              <a-menu-item key="tenant" @click="usersUiStore.openModal('createTenant')">
                Usuario de tenant
              </a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>
      </template>
    </AppHeader>

    <UserFilters v-model:filters="filtersModel" />

    <a-tabs v-model:activeKey="activeTab" class="users-tabs">
      <!-- Tab Plataforma -->
      <a-tab-pane key="platform" tab="Plataforma">
        <div class="page-toolbar">
          <a-select
            v-model:value="platformRoleGuid"
            
            placeholder="Todos los roles"
            allow-clear
            :options="platformRoleOptions"
            @change="() => (filters.page = 1)"
          />
          <ColumnSelectorButton @click="isDrawerOpen = true" />
        </div>

        <UsersTable
          type="platform"
          :users="data?.data ?? []"
          :loading="isLoading"
          :columns="visibleColumns"
          @edit="(user) => usersUiStore.openModal('edit', user)"
          @delete="(user) => deleteUser(user)"
          @change-password="(user) => usersUiStore.openModal('changePassword', user)"
          @toggle-lock="(user) => toggleLockUser(user)"
          @reset-password="resetPassword"
        />
      </a-tab-pane>

      <!-- Tab Tenant -->
      <a-tab-pane key="tenant" tab="Tenant">
        <div class="page-toolbar">
          <TenantRoleSelect
            v-model="tenantRoleGuid"
            style="max-width: 200px"
            placeholder="Todos los roles"
            @update:model-value="() => (filters.page = 1)"
          />
          <ColumnSelectorButton @click="isDrawerOpen = true" />
        </div>

        <UsersTable
          type="tenant"
          :users="data?.data ?? []"
          :loading="isLoading"
          :columns="visibleColumns"
          @edit="(user) => usersUiStore.openModal('edit', user)"
          @delete="(user) => deleteUser(user)"
          @change-password="(user) => usersUiStore.openModal('changePassword', user)"
          @toggle-lock="(user) => toggleLockUser(user)"
          @reset-password="resetPassword"
        />
      </a-tab-pane>
    </a-tabs>

    <BasePagination
      :page="filters.page"
      :total="data?.total ?? 0"
      :per-page="filters.per_page"
      @change="({ page, perPage }) => { filters.page = page; filters.per_page = perPage }"
    />

    <CreateUserModal v-model="showCreate" />
    <CreateTenantUserModal v-model="showCreateTenant" />
    <EditUserModal v-model="showEdit" :user="usersUiStore.selectedUser" />
    <ChangePasswordModal v-model="showChangePassword" :user="usersUiStore.selectedUser" />
    <ResetPasswordModal v-model="showResetResult" :password="newPassword" />

    <ColumnSelectorDrawer
      v-model="isDrawerOpen"
      v-model:keys="visibleKeys"
      :columns="selectableColumns"
    />
  </div>
</template>

<style scoped>
.page-toolbar {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-bottom: 8px;
}

.users-tabs :deep(.ant-tabs-tabpane) {
  padding-top: 0;
}
</style>
