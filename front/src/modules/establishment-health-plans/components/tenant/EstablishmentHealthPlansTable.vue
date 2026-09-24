<script setup lang="ts">
import { useRouter } from 'vue-router'
import { EyeOutlined, StopOutlined } from '@ant-design/icons-vue'
import BaseTableActions from '@/components/tables/BaseTableActions.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import type { EstablishmentHealthPlanListItem } from '../../types/establishment-health-plan.types'

defineProps<{
  plans: EstablishmentHealthPlanListItem[]
  loading: boolean
}>()

const emit = defineEmits<{
  cancel: [plan: EstablishmentHealthPlanListItem]
}>()

const router = useRouter()

function goToDetail(plan: EstablishmentHealthPlanListItem) {
  router.push({ name: 'vet-health-plans-detail', params: { guid: plan.guid } })
}

const columns = [
  { title: 'Cliente', key: 'client', dataIndex: 'client' },
  { title: 'Establecimiento', key: 'establishment', dataIndex: 'establishment' },
  { title: 'Plantilla', key: 'template', dataIndex: 'template' },
  { title: 'Año', key: 'year', dataIndex: 'year' },
  { title: 'Actividades', key: 'activities_count', dataIndex: 'activities_count' },
  { title: 'Estado', key: 'status', dataIndex: 'status' },
  { title: '', key: 'actions' },
]
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="plans"
    :loading="loading"
    row-key="guid"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'client'">
        {{ (record as EstablishmentHealthPlanListItem).client.name }}
      </template>
      <template v-else-if="column.key === 'establishment'">
        {{ (record as EstablishmentHealthPlanListItem).establishment.name }}
      </template>
      <template v-else-if="column.key === 'template'">
        {{ (record as EstablishmentHealthPlanListItem).template.name }}
      </template>
      <template v-else-if="column.key === 'year'">
        {{ (record as EstablishmentHealthPlanListItem).year }}
      </template>
      <template v-else-if="column.key === 'activities_count'">
        <a-tag v-if="(record as EstablishmentHealthPlanListItem).pending_count > 0" color="blue">
          {{ (record as EstablishmentHealthPlanListItem).pending_count }} pendientes
        </a-tag>
        <span v-else>{{ (record as EstablishmentHealthPlanListItem).activities_count }}</span>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag v-if="(record as EstablishmentHealthPlanListItem).cancelled_at" color="red">Cancelado</a-tag>
        <a-tag v-else color="green">Activo</a-tag>
      </template>
      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton
            variant="row-action"
            size="small"
            tooltip="Ver detalle"
            @click="goToDetail(record as EstablishmentHealthPlanListItem)"
          >
            <template #icon><EyeOutlined /></template>
          </BaseButton>
          <PermissionGuard
            v-if="(record as EstablishmentHealthPlanListItem).editable"
            permission="establishment-health-plans.update"
          >
            <BaseButton
              variant="row-action"
              size="small"
              danger
              tooltip="Cancelar plan"
              @click="emit('cancel', record as EstablishmentHealthPlanListItem)"
            >
              <template #icon><StopOutlined /></template>
            </BaseButton>
          </PermissionGuard>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>
