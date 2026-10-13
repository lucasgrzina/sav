<script setup lang="ts">
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import type { SelectOption } from '@/core/types/ui.types'
import { ALERTS_COPY } from '../../../constants/alert-copy'
import type { AlertType } from '../../../types/program-alerts.types'

defineProps<{
  profile: string | null
  target: string | null
  type: AlertType | null
  profileOptions: SelectOption[]
  targetOptions: SelectOption[]
  typeOptions: SelectOption[]
}>()

const emit = defineEmits<{
  'update:profile': [value: string | null]
  'update:target': [value: string | null]
  'update:type': [value: AlertType | null]
}>()
</script>

<template>
  <div class="paf-root">
    <BaseSelect
      :model-value="profile"
      :options="profileOptions"
      :placeholder="ALERTS_COPY.allPeople"
      data-testid="alerts-filter-profile"
      @update:model-value="emit('update:profile', ($event as string | null) ?? null)"
    />
    <BaseSelect
      :model-value="target"
      :options="targetOptions"
      :placeholder="ALERTS_COPY.allGroups"
      data-testid="alerts-filter-target"
      @update:model-value="emit('update:target', ($event as string | null) ?? null)"
    />
    <BaseSelect
      :model-value="type"
      :options="typeOptions"
      :placeholder="ALERTS_COPY.allTypes"
      data-testid="alerts-filter-type"
      @update:model-value="emit('update:type', ($event as AlertType | null) ?? null)"
    />
  </div>
</template>

<style scoped>
.paf-root {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
}

@media (min-width: 768px) {
  .paf-root {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
