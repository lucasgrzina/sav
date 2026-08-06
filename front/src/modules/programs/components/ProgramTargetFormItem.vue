<script setup lang="ts">
import { computed } from 'vue'
import { DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { ProgramTargetFormValues } from '../validators/program.validator'
import type { AnimalOption } from '../types/program.types'

const props = withDefaults(
  defineProps<{
    target: ProgramTargetFormValues
    index: number
    canRemove: boolean
    animalOptions: AnimalOption[]
    animalSearchLoading: boolean
    // DEC-13: label dinámico de la fecha del grupo (technique.target_date_name).
    dateLabel?: string
  }>(),
  { dateLabel: 'Fecha objetivo' },
)

const emit = defineEmits<{
  update: [index: number, value: ProgramTargetFormValues]
  remove: [index: number]
  search: [value: string]
}>()

function update<K extends keyof ProgramTargetFormValues>(field: K, value: ProgramTargetFormValues[K]) {
  emit('update', props.index, { ...props.target, [field]: value })
}

const selectedTags = computed(() => props.target.animals.map((a) => a.id ?? a.rp))

const selectOptions = computed(() => {
  const searched = props.animalOptions.map((a) => ({
    label: a.rp + (a.name ? ` (${a.name})` : ''),
    value: a.guid,
  }))

  // Animales ya cargados en el target (modo edición) que todavía no aparecen en los
  // resultados de búsqueda: sin esta entrada, a-select no encuentra el label y muestra
  // el guid crudo en el tag.
  const alreadySelected = props.target.animals
    .filter((a): a is { id: string; rp: string } => Boolean(a.id) && !searched.some((o) => o.value === a.id))
    .map((a) => ({ label: a.rp, value: a.id }))

  return [...searched, ...alreadySelected]
})

// DEC-07/DEC-10: por cada valor seleccionado, si coincide con un guid de un resultado real de
// búsqueda, arma { id: guid, rp } resuelto; si no matchea ningún resultado conocido es un tag
// "libre" tipeado por el usuario y se interpreta como RP nuevo (sin id).
function handleAnimalsChange(values: string[]) {
  const animals = values.map((value) => {
    const matched = props.animalOptions.find((a) => a.guid === value)
    if (matched) {
      return { id: matched.guid, rp: matched.rp }
    }
    return { rp: value }
  })
  update('animals', animals)
}
</script>

<template>
  <div class="ptfi-root">
    <div class="ptfi-header">
      <span class="ptfi-title">Grupo {{ index + 1 }}</span>
      <BaseButton
        variant="row-action"
        size="small"
        danger
        :disabled="!canRemove"
        tooltip="Quitar objetivo"
        @click="emit('remove', index)"
      >
        <template #icon><DeleteOutlined /></template>
      </BaseButton>
    </div>

    <a-row :gutter="16">
      <a-col :xs="24" :md="12" :lg="8">
        <a-form-item :label="dateLabel" required class="ptfi-field">
          <a-date-picker
            :value="target.target_date || undefined"
            value-format="YYYY-MM-DD"
            format="DD/MM/YYYY"
            placeholder="Elegí una fecha"
            style="width: 100%"
            @update:value="(v: string | undefined) => update('target_date', v ?? '')"
          />
        </a-form-item>
      </a-col>
      <a-col :xs="24">
        <a-form-item label="Animales" class="ptfi-field">
          <a-select
            :value="selectedTags"
            mode="tags"
            :options="selectOptions"
            :filter-option="false"
            :loading="animalSearchLoading"
            placeholder="Buscar RP existente o escribir uno nuevo"
            style="width: 100%"
            @search="emit('search', $event)"
            @change="handleAnimalsChange"
          />
        </a-form-item>
      </a-col>
    </a-row>



  </div>
</template>

<style scoped>
.ptfi-root {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px;
  /*border-bottom: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));*/
  border-radius: 10px;
  background-color: var(--dt-bg);
}

.ptfi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.ptfi-title {
  font-weight: 600;
}

.ptfi-field {
  margin-bottom: 0;
}
</style>
