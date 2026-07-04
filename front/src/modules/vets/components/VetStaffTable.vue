<script setup lang="ts">
import BaseStaffTable from '@/components/shared/BaseStaffTable.vue'
import type { StaffRecord } from '@/components/shared/BaseStaffTable.vue'
import type { VetStaffItem } from '../types/vet.types'
import type { TableColumnDef } from '@/core/composables/useColumnVisibility'

defineProps<{
  staff: VetStaffItem[]
  loading: boolean
  columns?: TableColumnDef[]
}>()

const emit = defineEmits<{
  edit:           [member: VetStaffItem]
  'toggle-block': [member: VetStaffItem]
  unlink:         [member: VetStaffItem]
}>()
</script>

<template>
  <BaseStaffTable
    :staff="staff"
    :loading="loading"
    :columns="columns"
    blocked-label="Bloqueado (vet)"
    unlink-tooltip="Desvincular de esta vet"
    unlink-variant="disconnect"
    @edit="(m: StaffRecord) => emit('edit', m as VetStaffItem)"
    @toggle-block="(m: StaffRecord) => emit('toggle-block', m as VetStaffItem)"
    @unlink="(m: StaffRecord) => emit('unlink', m as VetStaffItem)"
  />
</template>
