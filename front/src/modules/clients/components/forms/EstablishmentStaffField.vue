<script setup lang="ts">
import { computed } from 'vue'
import BaseMultiSelect from '@/components/atoms/selects/BaseMultiSelect.vue'
import BaseAlert from '@/components/atoms/feedback/BaseAlert.vue'
import { getRoleLabel } from '@/core/utils/roles'
import { removedGuids } from '../../utils/establishment-staff'
import type { SelectOption } from '@/core/types/ui.types'
import type { EstablishmentStaffItem } from '../../types/client.types'

const props = withDefaults(defineProps<{
  staffOptions: EstablishmentStaffItem[]
  // Guids linked when the modal was opened (edit mode); used to warn about unlinking
  initialGuids?: string[]
  loading?: boolean
  error?: string
}>(), {
  initialGuids: () => [],
  loading: false,
  error: '',
})

const emit = defineEmits<{ change: [] }>()

const selected = defineModel<string[]>({ default: () => [] })

const options = computed<SelectOption[]>(() =>
  props.staffOptions.map((member) => ({
    value: member.guid,
    label: `${member.user.name} (${getRoleLabel(member.role.name)})`,
  })),
)

const hasRemovals = computed(() => removedGuids(props.initialGuids, selected.value).length > 0)
const hasNoStaff = computed(() => !props.loading && props.staffOptions.length === 0)
const hasNoSelection = computed(() => selected.value.length === 0)

function onChange(values: (string | number)[]): void {
  selected.value = values.map(String)
  emit('change')
}
</script>

<template>
  <a-form-item
    label="Personal vinculado"
    :validate-status="error ? 'error' : ''"
    :help="error || 'Solo el personal vinculado verá este establecimiento y podrá recibir alertas de sus programas.'"
  >
    <BaseMultiSelect
      :model-value="selected"
      :options="options"
      :loading="loading"
      :disabled="hasNoStaff"
      :max-tag-count="50"
      placeholder="Seleccioná el personal del cliente"
      aria-label="Personal vinculado al establecimiento"
      @update:model-value="onChange"
    />

    <p v-if="hasNoStaff" class="mt-2 text-xs text-gray-500">
      Este cliente todavía no tiene personal cargado. Agregalo desde la pestaña de personal.
    </p>
    <p v-else-if="hasNoSelection && !loading" class="mt-2 text-xs text-amber-600">
      Sin personal vinculado: nadie del cliente tendrá acceso ni recibirá alertas de este establecimiento.
    </p>

    <BaseAlert
      v-if="hasRemovals"
      class="mt-2"
      type="warning"
      show-icon
      message="Se quitará como responsable de los programas activos de este establecimiento."
    />
  </a-form-item>
</template>
