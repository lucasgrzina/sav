<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import TechniqueFilters from '../components/TechniqueFilters.vue'
import TechniqueTypeBadge from '../components/TechniqueTypeBadge.vue'
import TechniqueDeleteModal from '../components/TechniqueDeleteModal.vue'
import { useTechniqueList } from '../composables/useTechniqueList'
import { useDeleteTechnique } from '../composables/useTechniqueMutations'
import type { TechniqueListItem, TechniqueListParams } from '../types/technique.types'

const router = useRouter()

// Filtros reactivos
const filters = reactive<TechniqueListParams>({
  page: 1,
  per_page: 15,
})

const { data, isLoading } = useTechniqueList(filters)

const {
  mutate: mutateDelete,
  isPending: isDeleting,
  deleteBlockedError,
  clearDeleteError,
} = useDeleteTechnique()

// Estado del modal de eliminación (local, sin Pinia — según DEC-09)
const deleteTarget = ref<TechniqueListItem | null>(null)
const showDeleteModal = ref(false)

function openDeleteModal(technique: TechniqueListItem) {
  deleteTarget.value = technique
  showDeleteModal.value = true
  clearDeleteError()
}

function onDeleteCancel() {
  showDeleteModal.value = false
  deleteTarget.value = null
  clearDeleteError()
}

function onDeleteConfirm() {
  if (!deleteTarget.value) return
  mutateDelete(deleteTarget.value.guid, {
    onSuccess: () => {
      showDeleteModal.value = false
      deleteTarget.value = null
    },
  })
}

const columns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Tipo', key: 'type', width: 120 },
  { title: 'Sub-técnicas', key: 'children_count', dataIndex: 'children_count', width: 140 },
  { title: 'Acciones', key: 'actions', width: 140, alwaysVisible: true },
]
</script>

<template>
  <div>
    <AppHeader
      title="Técnicas de Reproducción"
      subtitle="Gestioná las técnicas y vacunas del sistema"
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="techniques.create">
          <BaseButton :size="buttonSize" @click="router.push('/techniques/create')">
            <template #icon><PlusOutlined /></template>
            Nueva técnica
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <TechniqueFilters
      :filters="filters"
      @update:filters="(v) => Object.assign(filters, v)"
    />

    <BaseDataTable
      :columns="columns"
      :data-source="data?.data ?? []"
      :loading="isLoading"
      row-key="guid"
      :scroll="{ x: 700 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a class="tlp-name-link" @click="router.push(`/techniques/${record.guid}`)">
            {{ record.name }}
          </a>
        </template>

        <template v-else-if="column.key === 'type'">
          <TechniqueTypeBadge :type="record.type" />
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="techniques.read">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Ver detalle"
                @click="router.push(`/techniques/${record.guid}`)"
              >
                <template #icon><EyeOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="techniques.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="router.push(`/techniques/${record.guid}/edit`)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="techniques.delete">
              <BaseButton
                variant="row-action"
                size="small"
                danger
                tooltip="Eliminar"
                @click="openDeleteModal(record)"
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

    <TechniqueDeleteModal
      v-model="showDeleteModal"
      :technique="deleteTarget"
      :is-pending="isDeleting"
      :blocked-error="deleteBlockedError"
      @confirm="onDeleteConfirm"
      @cancel="onDeleteCancel"
    />
  </div>
</template>

<style scoped>
.tlp-name-link {
  color: var(--dt-accent, #1AE5A0);
  cursor: pointer;
  font-weight: 500;
  text-decoration: none;
  transition: opacity 0.15s;
}
.tlp-name-link:hover {
  opacity: 0.8;
}
</style>
