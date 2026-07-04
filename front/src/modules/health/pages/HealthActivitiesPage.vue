<script setup lang="ts">
import { ref, reactive } from 'vue'
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import HealthActivityDrawer from '../components/HealthActivityDrawer.vue'
import { useHealthActivities } from '../composables/useHealthActivities'
import { useDeleteHealthActivity } from '../composables/useHealthActivityMutations'
import type { HealthActivity, HealthActivityListParams } from '../types/health.types'

const filters = reactive<HealthActivityListParams>({ page: 1, per_page: 15 })

const { data, isLoading } = useHealthActivities(filters)

const {
  mutate: mutateDelete,
  isPending: isDeleting,
  blockedMessage,
  clearBlockedMessage,
} = useDeleteHealthActivity()

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editTarget = ref<HealthActivity | null>(null)

function openCreate() {
  drawerMode.value = 'create'
  editTarget.value = null
  drawerOpen.value = true
}

function openEdit(activity: HealthActivity) {
  drawerMode.value = 'edit'
  editTarget.value = activity
  drawerOpen.value = true
}

function onDeleteConfirm(activity: HealthActivity) {
  clearBlockedMessage()
  mutateDelete(activity.guid)
}

const columns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Descripción', key: 'description', dataIndex: 'description' },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]
</script>

<template>
  <div>
    <AppHeader
      title="Actividades Sanitarias"
      subtitle="Gestioná el catálogo de actividades del sistema"
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="health-activities.create">
          <BaseButton :size="buttonSize" @click="openCreate">
            <template #icon><PlusOutlined /></template>
            Nueva actividad
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <div style="margin-bottom: 16px">
      <a-input-search
        v-model:value="filters.search"
        placeholder="Buscar actividad..."
        style="max-width: 320px"
        @search="() => { filters.page = 1 }"
        allow-clear
      />
    </div>

    <a-alert
      v-if="blockedMessage"
      :message="blockedMessage"
      type="warning"
      show-icon
      closable
      style="margin-bottom: 16px"
      @close="clearBlockedMessage"
    />

    <BaseDataTable
      :columns="columns"
      :data-source="data?.data ?? []"
      :loading="isLoading || isDeleting"
      row-key="guid"
      :scroll="{ x: 600 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'description'">
          <span style="color: var(--dt-text-secondary, #888)">
            {{ record.description ?? '—' }}
          </span>
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="health-activities.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="openEdit(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="health-activities.delete">
              <BaseButton
                variant="row-action"
                size="small"
                danger
                tooltip="Eliminar"
                @click="onDeleteConfirm(record)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <BasePagination
      v-if="data"
      :page="data.current_page"
      :total="data.total"
      :per-page="data.per_page"
      @change="({ page, perPage }) => { filters.page = page; filters.per_page = perPage }"
    />

    <HealthActivityDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :activity="editTarget"
    />
  </div>
</template>
