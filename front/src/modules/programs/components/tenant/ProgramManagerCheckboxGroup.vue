<script setup lang="ts">
import RoleChip from '@/components/atoms/display/RoleChip.vue'
import type { ProgramManagerOption } from '@/modules/programs/types/program.types'

// DEC-12: átomo compartido por ambos bloques de Responsables (Vet / Cliente) —
// se instancia dos veces en ProgramClientSection en vez de duplicar markup.
// Se renderiza con a-checkbox individuales (en vez de :options de a-checkbox-group)
// porque necesitamos insertar el RoleChip junto al nombre de cada responsable.
const props = withDefaults(
  defineProps<{
    options: ProgramManagerOption[]
    modelValue: string[]
    loading?: boolean
    emptyText?: string
  }>(),
  { loading: false, emptyText: 'No hay responsables disponibles.' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

function onToggle(guid: string, checked: boolean) {
  const next = checked
    ? [...props.modelValue, guid]
    : props.modelValue.filter((v) => v !== guid)
  emit('update:modelValue', next)
}
</script>

<template>
  <div class="pmcg-root">
    <a-spin v-if="loading" size="small" />
    <span v-else-if="!options.length" class="pmcg-empty">{{ emptyText }}</span>
    <div v-else class="pmcg-group">
      <a-checkbox
        v-for="option in options"
        :key="option.guid"
        :checked="modelValue.includes(option.guid)"
        @change="(e: { target: { checked: boolean } }) => onToggle(option.guid, e.target.checked)"
      >
        <span class="pmcg-option">
          {{ option.label }}
          <RoleChip :role="option.role" />
        </span>
      </a-checkbox>
    </div>
  </div>
</template>

<style scoped>
.pmcg-root {
  display: flex;
  flex-direction: column;
}

.pmcg-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.pmcg-option {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.pmcg-empty {
  color: var(--dt-text-secondary, rgba(255, 255, 255, 0.45));
  font-size: 13px;
}
</style>
