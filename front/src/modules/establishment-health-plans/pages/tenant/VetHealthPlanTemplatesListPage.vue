<script setup lang="ts">
import { ref, reactive } from 'vue'
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import VetHealthPlanTemplateDrawer from '../../components/tenant/VetHealthPlanTemplateDrawer.vue'
import { useHealthPlanTemplateCatalog, useHealthPlanTemplateCatalogDetail } from '../../composables/useHealthPlanTemplateCatalog'
import { useDeleteHealthPlanTemplateCatalog } from '../../composables/useHealthPlanTemplateCatalogMutations'
import type { HealthPlanTemplateListItem, HealthPlanTemplateListParams } from '@/modules/health/types/health.types'

const activeTab = ref<'own' | 'global'>('own')

const ownFilters = reactive<HealthPlanTemplateListParams>({ page: 1, per_page: 15, scope: 'own' })
const globalFilters = reactive<HealthPlanTemplateListParams>({ page: 1, per_page: 15, scope: 'global' })

const { data: ownData, isLoading: isLoadingOwn } = useHealthPlanTemplateCatalog(ownFilters)
const { data: globalData, isLoading: isLoadingGlobal } = useHealthPlanTemplateCatalog(globalFilters)

const { mutate: mutateDelete, isPending: isDeleting } = useDeleteHealthPlanTemplateCatalog()

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editGuid = ref<string | null>(null)

const { data: editTemplate } = useHealthPlanTemplateCatalogDetail(editGuid)

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

const ownColumns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Categoría', key: 'category', width: 200 },
  { title: 'Actividades', key: 'activities_count', dataIndex: 'activities_count', width: 120 },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]

const globalColumns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Categoría', key: 'category', width: 200 },
  { title: 'Actividades', key: 'activities_count', dataIndex: 'activities_count', width: 120 },
]
</script>

<template>
  <div>
    <AppHeader
      title="Plantillas de plan sanitario"
      subtitle="Gestioná tus propias plantillas o consultá las del catálogo del sistema."
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="establishment-health-plans.templates.create">
          <BaseButton :size="buttonSize" @click="openCreate">
            <template #icon><PlusOutlined /></template>
            Nueva plantilla
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <a-tabs v-model:active-key="activeTab">
      <a-tab-pane key="own" tab="Mis plantillas">
        <BaseDataTable
          :columns="ownColumns"
          :data-source="ownData?.data ?? []"
          :loading="isLoadingOwn || isDeleting"
          row-key="guid"
          :scroll="{ x: 700 }"
          :pagination="false"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'category'">
              {{ (record as HealthPlanTemplateListItem).category?.name ?? '—' }}
            </template>

            <template v-else-if="column.key === 'actions'">
              <BaseTableActions>
                <PermissionGuard permission="establishment-health-plans.templates.update">
                  <BaseButton
                    variant="row-action"
                    size="small"
                    :disabled="(record as HealthPlanTemplateListItem).is_locked"
                    :tooltip="(record as HealthPlanTemplateListItem).is_locked
                      ? 'Ya generó planes sanitarios, no se puede editar'
                      : 'Editar'"
                    @click="openEdit(record as HealthPlanTemplateListItem)"
                  >
                    <template #icon><EditOutlined /></template>
                  </BaseButton>
                </PermissionGuard>

                <PermissionGuard permission="establishment-health-plans.templates.delete">
                  <BaseButton
                    variant="row-action"
                    size="small"
                    danger
                    :disabled="(record as HealthPlanTemplateListItem).is_locked"
                    :tooltip="(record as HealthPlanTemplateListItem).is_locked
                      ? 'Ya generó planes sanitarios, no se puede eliminar'
                      : 'Eliminar'"
                    @click="onDeleteConfirm(record as HealthPlanTemplateListItem)"
                  >
                    <template #icon><DeleteOutlined /></template>
                  </BaseButton>
                </PermissionGuard>
              </BaseTableActions>
            </template>
          </template>
        </BaseDataTable>

        <BasePagination
          v-if="ownData"
          :page="ownData.current_page"
          :total="ownData.total"
          :per-page="ownData.per_page"
          @change="({ page, perPage }: { page: number; perPage: number }) => { ownFilters.page = page; ownFilters.per_page = perPage }"
        />
      </a-tab-pane>

      <a-tab-pane key="global" tab="Plantillas del sistema">
        <BaseDataTable
          :columns="globalColumns"
          :data-source="globalData?.data ?? []"
          :loading="isLoadingGlobal"
          row-key="guid"
          :scroll="{ x: 700 }"
          :pagination="false"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'category'">
              {{ (record as HealthPlanTemplateListItem).category?.name ?? '—' }}
            </template>
          </template>
        </BaseDataTable>

        <BasePagination
          v-if="globalData"
          :page="globalData.current_page"
          :total="globalData.total"
          :per-page="globalData.per_page"
          @change="({ page, perPage }: { page: number; perPage: number }) => { globalFilters.page = page; globalFilters.per_page = perPage }"
        />
      </a-tab-pane>
    </a-tabs>

    <VetHealthPlanTemplateDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :template="drawerMode === 'edit' ? (editTemplate ?? null) : null"
    />
  </div>
</template>
