<script setup lang="ts">
import { useRouter } from 'vue-router'
import { EyeOutlined, EditOutlined } from '@ant-design/icons-vue'
import type { ClientItem } from '../../types/client.types'
import { formatDate } from '@/core/utils/date'

defineProps<{
  clients: ClientItem[]
  loading: boolean
}>()

const router = useRouter()

const columns = [
  { title: 'Nombre',    key: 'name' },
  { title: 'CUIT/Doc.', key: 'tax_id' },
  { title: 'País',      key: 'country' },
  { title: 'Alta',      key: 'created_at' },
  { title: 'Acciones',  key: 'actions', width: 110 },
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
        <span class="act-name">{{ record.name }}</span>
      </template>

      <template v-else-if="column.key === 'tax_id'">
        <span class="act-taxid">{{ record.tax_id }}</span>
      </template>

      <template v-else-if="column.key === 'country'">
        <span v-if="record.country">{{ record.country.name }}</span>
        <span v-else class="act-muted">—</span>
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
            @click="router.push(`/admin/clients/${record.guid}`)"
          >
            <template #icon><EyeOutlined /></template>
          </BaseButton>

          <PermissionGuard permission="clients.update">
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Editar"
              @click="router.push(`/admin/clients/${record.guid}/edit`)"
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
.act-name   { font-weight: 600; color: var(--dt-title, #fff); }
.act-taxid  { font-family: monospace; font-size: 12px; color: var(--dt-text, #C8E2EF); }
.act-muted  { color: var(--dt-muted, #6B8CAE); font-style: italic; }
</style>
