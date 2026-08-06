<script setup lang="ts">
import { EyeOutlined, EditOutlined, DeleteOutlined, CopyOutlined } from '@ant-design/icons-vue'
import BaseTableActions from '@/components/tables/BaseTableActions.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import type { VetProtocolListItem } from '../../types/vet-protocol.types'

defineProps<{
  protocols: VetProtocolListItem[]
  loading: boolean
}>()

const emit = defineEmits<{
  view: [protocol: VetProtocolListItem]
  edit: [protocol: VetProtocolListItem]
  delete: [protocol: VetProtocolListItem]
  'create-version': [protocol: VetProtocolListItem]
}>()

const columns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Sub-técnica', key: 'technique', dataIndex: 'technique' },
  { title: 'Origen', key: 'origin', dataIndex: 'origin' },
  { title: 'Tareas', key: 'tasks_count', dataIndex: 'tasks_count' },
  { title: 'Creado', key: 'created_at', dataIndex: 'created_at' },
  { title: '', key: 'actions' },
]
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="protocols"
    :loading="loading"
    row-key="guid"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'technique'">
        {{ (record as VetProtocolListItem).technique.name }}
      </template>
      <template v-else-if="column.key === 'origin'">
        <a-tag v-if="(record as VetProtocolListItem).is_global" color="blue">Global</a-tag>
        <a-tag v-else color="green">Propio</a-tag>
      </template>
      <template v-else-if="column.key === 'created_at'">
        {{ new Date((record as VetProtocolListItem).created_at).toLocaleDateString('es-AR') }}
      </template>
      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton
            variant="row-action"
            size="small"
            tooltip="Ver detalle"
            @click="emit('view', record as VetProtocolListItem)"
          >
            <template #icon><EyeOutlined /></template>
          </BaseButton>

          <template v-if="(record as VetProtocolListItem).is_own">
            <PermissionGuard permission="protocols.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar protocolo"
                @click="emit('edit', record as VetProtocolListItem)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>
            <PermissionGuard permission="protocols.delete">
              <BaseButton
                variant="row-action"
                size="small"
                danger
                tooltip="Eliminar protocolo"
                @click="emit('delete', record as VetProtocolListItem)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </template>

          <PermissionGuard v-if="!(record as VetProtocolListItem).is_own" permission="protocols.create">
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Crear versión"
              @click="emit('create-version', record as VetProtocolListItem)"
            >
              <template #icon><CopyOutlined /></template>
            </BaseButton>
          </PermissionGuard>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>
