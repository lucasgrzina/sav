<script setup lang="ts">
import BaseStaffTable from '@/components/shared/BaseStaffTable.vue'
import type { StaffRecord } from '@/components/shared/BaseStaffTable.vue'
import type { ClientStaffItem } from '../../types/client.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'

defineProps<{
  staff: ClientStaffItem[]
  loading: boolean
  columns?: TableColumnDef[]
}>()

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
    :columns="columns"
    unlink-tooltip="Eliminar del cliente"
    @edit="(m: StaffRecord) => emit('edit', m as ClientStaffItem)"
    @toggle-block="(m: StaffRecord) => emit('toggle-block', m as ClientStaffItem)"
    @unlink="(m: StaffRecord) => emit('unlink', m as ClientStaffItem)"
  />
</template>
