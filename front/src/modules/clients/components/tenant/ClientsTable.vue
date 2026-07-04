<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { EyeOutlined, EditOutlined, DisconnectOutlined } from '@ant-design/icons-vue'
import type { ClientItem } from '../../types/client.types'
import { formatDate } from '@/core/utils/date'

const props = defineProps<{
  clients: ClientItem[]
  loading: boolean
}>()

const emit = defineEmits<{
  unlink: [client: ClientItem]
}>()

const router = useRouter()
const route  = useRoute()
const vetGuid = computed(() => route.params.vetGuid as string)

const columns = [
  { title: 'Nombre',    key: 'name' },
  { title: 'CUIT/Doc.', key: 'tax_id' },
  { title: 'País',      key: 'country' },
  { title: 'Alta',      key: 'created_at' },
  { title: 'Acciones',  key: 'actions', width: 130 },
]
</script>

<template>
  <BaseDataTable
    :columns="columns"
    :data-source="clients"
    :loading="loading"
    row-key="guid"
    :scroll="{ x: 700 }"
    :pagination="false"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'name'">
        <span class="ct-name">{{ record.name }}</span>
      </template>

      <template v-else-if="column.key === 'tax_id'">
        <span class="ct-taxid">{{ record.tax_id }}</span>
      </template>

      <template v-else-if="column.key === 'country'">
        <span v-if="record.country">{{ record.country.name }}</span>
        <span v-else class="ct-muted">—</span>
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
            @click="router.push(`/vets/${vetGuid}/clients/${record.guid}`)"
          >
            <template #icon><EyeOutlined /></template>
          </BaseButton>

          <PermissionGuard permission="clients.update">
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Editar"
              @click="router.push(`/vets/${vetGuid}/clients/${record.guid}/edit`)"
            >
              <template #icon><EditOutlined /></template>
            </BaseButton>
          </PermissionGuard>

          <PermissionGuard permission="clients.delete">
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Desvincular"
              danger
              @click="emit('unlink', record)"
            >
              <template #icon><DisconnectOutlined /></template>
            </BaseButton>
          </PermissionGuard>
        </BaseTableActions>
      </template>
    </template>
  </BaseDataTable>
</template>

<style scoped>
.ct-name   { font-weight: 600; color: var(--dt-title, #fff); }
.ct-taxid  { font-family: monospace; font-size: 12px; color: var(--dt-text, #C8E2EF); }
.ct-muted  { color: var(--dt-muted, #6B8CAE); font-style: italic; }
</style>
