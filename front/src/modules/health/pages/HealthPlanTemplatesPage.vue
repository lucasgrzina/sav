<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import HealthPlanTemplateDrawer from '../components/HealthPlanTemplateDrawer.vue'
import { useHealthPlanTemplates, useHealthPlanTemplate } from '../composables/useHealthPlanTemplates'
import { useDeleteHealthPlanTemplate } from '../composables/useHealthPlanTemplateMutations'
import { listAllHealthPlanCategoriesApi } from '../api/health-plan-categories.api'
import type { HealthPlanTemplateListItem, HealthPlanTemplateListParams } from '../types/health.types'

const filters = reactive<HealthPlanTemplateListParams>({ page: 1, per_page: 15 })

const { data, isLoading } = useHealthPlanTemplates(filters)

const { mutate: mutateDelete, isPending: isDeleting } = useDeleteHealthPlanTemplate()

const { data: allCategories } = useQuery({
  queryKey: ['admin-health-plan-categories-all'],
  queryFn: () => listAllHealthPlanCategoriesApi(),
  staleTime: 1000 * 60 * 5,
})

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editGuid = ref<string | null>(null)

const { data: editTemplate, isLoading: editLoading } = useHealthPlanTemplate(editGuid)

function openCreate() {
  drawerMode.value = 'create'
  editGuid.value = null
  drawerOpen.value = true
}

function openEdit(item: HealthPlanTemplateListItem) {
  drawerMode.value = 'edit'
  editGuid.value = item.guid
  drawerOpen.value = true
}

function onDeleteConfirm(item: HealthPlanTemplateListItem) {
  mutateDelete(item.guid)
}

const columns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Categoría', key: 'category', width: 200 },
  { title: 'Actividades', key: 'activities_count', dataIndex: 'activities_count', width: 120 },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]
</script>

<template>
  <div>
    <AppHeader
      title="Plantillas de Planes Sanitarios"
      subtitle="Gestioná las plantillas del catálogo de planes"
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="health-plan-templates.create">
          <BaseButton :size="buttonSize" @click="openCreate">
            <template #icon><PlusOutlined /></template>
            Nueva plantilla
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <div style="margin-bottom: 16px; display: flex; gap: 12px; flex-wrap: wrap">
      <a-input-search
        v-model:value="filters.search"
        placeholder="Buscar plantilla..."
        style="max-width: 280px"
        @search="() => { filters.page = 1 }"
        allow-clear
      />
      <a-select
        v-model:value="filters.health_plan_category_guid"
        placeholder="Filtrar por categoría"
        style="min-width: 220px"
        allow-clear
        @change="() => { filters.page = 1 }"
      >
        <a-select-option
          v-for="cat in allCategories ?? []"
          :key="cat.guid"
          :value="cat.guid"
        >
          {{ cat.name }}
        </a-select-option>
      </a-select>
    </div>

    <BaseDataTable
      :columns="columns"
      :data-source="data?.data ?? []"
      :loading="isLoading || isDeleting"
      row-key="guid"
      :scroll="{ x: 700 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'category'">
          {{ record.category?.name ?? '—' }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="health-plan-templates.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="openEdit(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="health-plan-templates.delete">
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

    <HealthPlanTemplateDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :template="drawerMode === 'edit' ? (editTemplate ?? null) : null"
    />
  </div>
</template>
