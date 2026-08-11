<script setup lang="ts">
import { useRouter } from 'vue-router'
import { EditOutlined, EyeOutlined, StopOutlined, MoreOutlined } from '@ant-design/icons-vue'
import BaseTableActions from '@/components/tables/BaseTableActions.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import type { ProgramListItem } from '../../types/program.types'

defineProps<{
  programs: ProgramListItem[]
  loading: boolean
}>()

const emit = defineEmits<{
  cancel: [program: ProgramListItem]
  'download-pdf': [program: ProgramListItem]
  'open-share': [program: ProgramListItem]
}>()

// DEC-11: las acciones navegan por router (página completa de alta/edición y detalle),
// ya no abren Drawer/modal.
const router = useRouter()

function goToDetail(program: ProgramListItem) {
  router.push({ name: 'vet-programs-detail', params: { guid: program.guid } })
}

function goToEdit(program: ProgramListItem) {
  router.push({ name: 'vet-programs-edit', params: { guid: program.guid } })
}

const columns = [
  { title: 'Cliente', key: 'client', dataIndex: 'client' },
  { title: 'Establecimiento', key: 'establishment', dataIndex: 'establishment' },
  { title: 'Protocolo', key: 'protocol', dataIndex: 'protocol' },
  { title: 'Próximo objetivo', key: 'next_target_date', dataIndex: 'next_target_date' },
  { title: 'Objetivos', key: 'targets_count', dataIndex: 'targets_count' },
  { title: 'Estado', key: 'status', dataIndex: 'status' },
  { title: '', key: 'actions' },
]
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="programs"
    :loading="loading"
    row-key="guid"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'client'">
        {{ (record as ProgramListItem).client.name }}
      </template>
      <template v-else-if="column.key === 'establishment'">
        {{ (record as ProgramListItem).establishment.name }}
      </template>
      <template v-else-if="column.key === 'protocol'">
        {{ (record as ProgramListItem).protocol.name }} ({{ (record as ProgramListItem).technique.name }})
      </template>
      <template v-else-if="column.key === 'next_target_date'">
        {{
          (record as ProgramListItem).next_target_date
            ? new Date((record as ProgramListItem).next_target_date as string).toLocaleDateString('es-AR')
            : '—'
        }}
      </template>
      <template v-else-if="column.key === 'targets_count'">
        <a-tag v-if="(record as ProgramListItem).targets_count > 1" color="blue">
          {{ (record as ProgramListItem).targets_count }} objetivos
        </a-tag>
        <span v-else>{{ (record as ProgramListItem).targets_count }}</span>
      </template>
      <template v-else-if="column.key === 'status'">
        <a-tag v-if="(record as ProgramListItem).cancelled_at" color="red">Cancelado</a-tag>
        <a-tag v-else color="green">Activo</a-tag>
      </template>
      <template v-else-if="column.key === 'actions'">
        <BaseTableActions>
          <BaseButton
            variant="row-action"
            size="small"
            tooltip="Ver detalle"
            @click="goToDetail(record as ProgramListItem)"
          >
            <template #icon><EyeOutlined /></template>
          </BaseButton>
          <template v-if="(record as ProgramListItem).editable">
            <PermissionGuard permission="programs.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar programa"
                @click="goToEdit(record as ProgramListItem)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>
            <PermissionGuard permission="programs.update">
              <BaseButton
                variant="row-action"
                size="small"
                danger
                tooltip="Cancelar programa"
                @click="emit('cancel', record as ProgramListItem)"
              >
                <template #icon><StopOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </template>
          <a-dropdown>
            <BaseButton variant="row-action" size="small" tooltip="Más acciones">
              <template #icon><MoreOutlined /></template>
            </BaseButton>
            <template #overlay>
              <a-menu>
                <a-menu-item-group title="Programa">
                  <a-menu-item key="download" @click="emit('download-pdf', record as ProgramListItem)">
                    Descargar
                  </a-menu-item>
                  <a-menu-item key="send" @click="emit('open-share', record as ProgramListItem)">
                    Enviar
                  </a-menu-item>
                </a-menu-item-group>
              </a-menu>
            </template>
          </a-dropdown>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>
