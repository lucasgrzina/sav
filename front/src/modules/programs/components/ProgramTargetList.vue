<script setup lang="ts">
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ProgramTargetFormItem from './ProgramTargetFormItem.vue'
import type { ProgramTargetFormValues } from '../validators/program.validator'
import type { AnimalOption } from '../types/program.types'

const props = withDefaults(
  defineProps<{
    animalOptions: AnimalOption[]
    animalSearchLoading: boolean
    // DEC-13: label dinámico de la fecha de cada grupo (technique.target_date_name).
    dateLabel?: string
  }>(),
  { dateLabel: 'Fecha objetivo' },
)

const emit = defineEmits<{
  search: [value: string]
}>()

const targets = defineModel<ProgramTargetFormValues[]>({ required: true })

function emptyTarget(): ProgramTargetFormValues {
  return { target_date: '', animals: [] }
}

function addTarget() {
  targets.value = [...targets.value, emptyTarget()]
}

// Mínimo 1 objetivo siempre presente: al intentar quitar la última fila, se limpia en vez de eliminarla.
function removeTarget(index: number) {
  if (targets.value.length <= 1) {
    const updated = [...targets.value]
    updated[index] = emptyTarget()
    targets.value = updated
    return
  }
  targets.value = targets.value.filter((_, i) => i !== index)
}

function updateTarget(index: number, value: ProgramTargetFormValues) {
  const updated = [...targets.value]
  updated[index] = value
  targets.value = updated
}
</script>

<template>
  <div class="ptl-root">
    <ProgramTargetFormItem
      v-for="(target, idx) in targets"
      :key="target.guid ?? `new-target-${idx}`"
      :target="target"
      :index="idx"
      :can-remove="targets.length > 1"
      :animal-options="props.animalOptions"
      :animal-search-loading="props.animalSearchLoading"
      :date-label="props.dateLabel"
      @update="updateTarget"
      @remove="removeTarget"
      @search="emit('search', $event)"
    />

    <BaseButton variant="secondary" size="small" @click="addTarget">
      <template #icon><PlusOutlined /></template>
      Agregar grupo de animales
    </BaseButton>
  </div>
</template>

<style scoped>
.ptl-root {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
</style>
