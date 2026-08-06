<script setup lang="ts">
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import ProgramTargetList from '../../ProgramTargetList.vue'
import type { ProgramTargetFormValues } from '../../../validators/program.validator'
import type { AnimalOption } from '../../../types/program.types'

// Nota de nomenclatura (DEC-11/DEC-16): el contrato de API sigue llamando "targets" al dato
// (ProgramTarget, campo `targets` del payload) — eso NO cambia acá. Solo cambia el LABEL
// visible en la UI, que pasa a ser "Grupos" por pedido explícito del usuario. Internamente
// reutiliza ProgramTargetList.vue / useProgramTargetRepeater sin cambios de lógica.
withDefaults(
  defineProps<{
    animalOptions: AnimalOption[]
    animalSearchLoading: boolean
    // DEC-13: label dinámico de la fecha de cada grupo, resuelto desde technique.target_date_name.
    dateLabel?: string
  }>(),
  { dateLabel: 'Fecha objetivo' },
)

const emit = defineEmits<{
  search: [value: string]
}>()

const targets = defineModel<ProgramTargetFormValues[]>({ required: true })
</script>

<template>
  <BaseCard title="Grupos">
    <ProgramTargetList
      v-model="targets"
      :animal-options="animalOptions"
      :animal-search-loading="animalSearchLoading"
      :date-label="dateLabel"
      @search="emit('search', $event)"
    />
  </BaseCard>
</template>
