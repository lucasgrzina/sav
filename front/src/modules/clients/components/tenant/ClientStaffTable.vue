<script setup lang="ts">
import { computed } from 'vue'
import BaseStaffTable from '@/components/shared/BaseStaffTable.vue'
import type { StaffRecord } from '@/components/shared/BaseStaffTable.vue'
import type { ClientStaffItem } from '../../types/client.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'

const clientStaffColumns: TableColumnDef[] = [
  { title: 'Nombre / Email',   key: 'user',       dataIndex: 'user' },
  { title: 'Rol',              key: 'role' },
  { title: 'Establecimientos', key: 'establishments' },
  { title: 'Estado',           key: 'status' },
  { title: 'Alta',             key: 'created_at' },
  { title: 'Acciones',         key: 'actions', width: 140, alwaysVisible: true },
]

const props = defineProps<{
  staff: ClientStaffItem[]
  loading: boolean
  columns?: TableColumnDef[]
}>()

const resolvedColumns = computed(() => props.columns ?? clientStaffColumns)

const emit = defineEmits<{
  edit:           [member: ClientStaffItem]
  'toggle-block': [member: ClientStaffItem]
  unlink:         [member: ClientStaffItem]
}>()
</script>

<template>
  <BaseStaffTable
    :staff="staff"
    :loading="loading"
    :columns="resolvedColumns"
    unlink-tooltip="Eliminar del cliente"
    @edit="(m: StaffRecord) => emit('edit', m as ClientStaffItem)"
    @toggle-block="(m: StaffRecord) => emit('toggle-block', m as ClientStaffItem)"
    @unlink="(m: StaffRecord) => emit('unlink', m as ClientStaffItem)"
  />
</template>
