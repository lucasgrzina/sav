<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { EyeOutlined, EditOutlined, MedicineBoxOutlined } from '@ant-design/icons-vue'
import VetStatusBadge from './VetStatusBadge.vue'
import type { VetItem } from '../types/vet.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'
import { formatDate } from '@/core/utils/date'
import { getVetStatus } from '../api/vets.mapper'

const props = defineProps<{
  vets: VetItem[]
  loading: boolean
  columns?: TableColumnDef[]
}>()

const router = useRouter()

const defaultColumns: TableColumnDef[] = [
  { title: 'Nombre', key: 'name' },
  { title: 'País', key: 'country' },
  { title: 'Estado', key: 'status' },
  { title: 'Creada', key: 'created_at' },
  { title: 'Acciones', key: 'actions', width: 120, alwaysVisible: true },
]

const columns = computed(() => props.columns ?? defaultColumns)
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="vets"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 800 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'name'">
        <div class="vt-name-cell">
          <div class="vt-icon-wrap">
            <MedicineBoxOutlined style="font-size:14px; color:#1AE5A0" />
          </div>
          <div>
            <div class="vt-name">{{ record.name }}</div>
            <div class="vt-slug">{{ record.slug }}</div>
          </div>
        </div>
      </template>

      <template v-else-if="column.key === 'country'">
        <span v-if="record.country">{{ record.country.name }}</span>
        <span v-else class="vt-muted">—</span>
      </template>

      <template v-else-if="column.key === 'status'">
        <VetStatusBadge :status="getVetStatus(record)" />
      </template>

      <template v-else-if="column.key === 'created_at'">
        {{ formatDate(record.created_at) }}
      </template>

      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton
            variant="row-action"
            size="small"
            tooltip="Ver detalle"
            @click="router.push(`/admin/vets/${record.guid}`)"
          >
            <template #icon><EyeOutlined /></template>
          </BaseButton>

          <PermissionGuard permission="vets.update">
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Editar"
              @click="router.push(`/admin/vets/${record.guid}/edit`)"
            >
              <template #icon><EditOutlined /></template>
            </BaseButton>
          </PermissionGuard>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>

<style scoped>
.vt-name-cell { display: flex; align-items: center; gap: 10px; }
.vt-icon-wrap {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: rgba(26,229,160,0.1);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.vt-name { font-weight: 600; }
.vt-slug { font-size: 11px; color: var(--dt-muted, #6B8CAE); }
.vt-muted { color: var(--dt-muted, #6B8CAE); font-style: italic; }
</style>
