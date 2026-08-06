<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseInput from '@/components/atoms/inputs/BaseInput.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import ProtocolTaskList from './ProtocolTaskList.vue'
import { useCountries } from '@/modules/vets/composables/useCountries'
import { protocolSchema } from '../validators/protocol.validator'
import type { ProtocolFormValues } from '../validators/protocol.validator'
import type { Technique } from '@/modules/techniques/types/technique.types'
import type { ProtocolDetail } from '../types/protocol.types'

const props = withDefaults(
  defineProps<{
    technique: Technique
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    initialValues?: ProtocolDetail | null
  }>(),
  { loading: false, initialValues: null },
)

const emit = defineEmits<{
  submit: [values: ProtocolFormValues]
  cancel: []
}>()

const open = defineModel<boolean>('open', { default: false })

const { data: countries, isLoading: isLoadingCountries } = useCountries()

const techniqueOptions = computed(() =>
  props.technique.children.map((child) => ({ value: child.guid as string, label: child.name })),
)

const countryOptions = computed(() => [
  { value: '', label: 'Global (todos los países)' },
  ...(countries.value ?? []).map((c) => ({ value: c.guid, label: c.name })),
])

function toFormValues(detail: ProtocolDetail | null): ProtocolFormValues {
  if (!detail) {
    return { technique_id: '', name: '', color: null, country_id: null, tasks: [] }
  }
  return {
    technique_id: detail.technique.guid,
    name: detail.name,
    color: detail.color,
    country_id: detail.country?.guid ?? null,
    tasks: detail.tasks.map((t) => ({
      guid: t.guid,
      description: t.description,
      days_offset: t.days_offset,
      time_of_day: t.time_of_day,
      time: t.time,
      important: t.important,
      alerts: t.alerts.map((a) => ({
        guid: a.guid,
        offset_days: a.offset_days,
        time_of_day: a.time_of_day,
        time: a.time,
        roles: a.roles,
        message: a.message,
        require_confirmation: a.require_confirmation,
      })),
    })),
  }
}

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm<ProtocolFormValues>({
  validationSchema: toTypedSchema(protocolSchema),
  initialValues: toFormValues(props.initialValues),
})

const [name, nameAttrs] = defineField('name')
const [color, colorAttrs] = defineField('color')
const [techniqueId, techniqueIdAttrs] = defineField('technique_id')
const [countryId] = defineField('country_id')
const [tasks] = defineField('tasks')

const onSubmit = handleSubmit((values) => emit('submit', values))

watch(
  () => props.fieldErrors,
  (errs) => setErrors(errs ?? {}),
)

watch(
  () => props.initialValues,
  (val) => {
    resetForm({ values: toFormValues(val) })
  },
)

const hasNoChildren = computed(() => props.technique.children.length === 0)

function onCountrySelect(value: string | number | null) {
  countryId.value = value === '' || value === null ? null : (value as string)
}
</script>

<template>
  <BaseDrawer
    v-model="open"
    :title="initialValues ? 'Editar protocolo' : 'Nuevo protocolo'"
    :width="560"
    @close="emit('cancel')"
  >
    <a-alert
      v-if="hasNoChildren"
      type="warning"
      show-icon
      message="Primero creá una sub-técnica para poder cargar protocolos."
      class="pfd-alert"
    />

    <a-form v-else layout="vertical" @submit.prevent="onSubmit">
      <a-form-item
        label="Sub-técnica"
        :validate-status="errors.technique_id ? 'error' : ''"
        :help="errors.technique_id ?? ''"
        required
      >
        <BaseSelect
          v-model="techniqueId"
          v-bind="techniqueIdAttrs"
          :options="techniqueOptions"
          placeholder="Seleccioná una sub-técnica"
        />
      </a-form-item>

      <a-form-item
        label="Nombre"
        :validate-status="errors.name ? 'error' : ''"
        :help="errors.name ?? ''"
        required
      >
        <BaseInput v-model="name" v-bind="nameAttrs" placeholder="Ej: Protocolo IATF estándar" />
      </a-form-item>

      <a-form-item
        label="Color"
        :validate-status="errors.color ? 'error' : ''"
        :help="errors.color ?? ''"
      >
        <input
          :value="color ?? '#1AE5A0'"
          v-bind="colorAttrs"
          type="color"
          class="pfd-color-input"
          @input="(e) => (color = (e.target as HTMLInputElement).value)"
        />
      </a-form-item>

      <a-form-item
        label="País"
        :validate-status="errors.country_id ? 'error' : ''"
        :help="errors.country_id ?? ''"
      >
        <BaseSelect
          :model-value="countryId ?? ''"
          :options="countryOptions"
          :loading="isLoadingCountries"
          :allow-clear="false"
          @update:model-value="onCountrySelect"
        />
      </a-form-item>

      <a-form-item label="Tareas">
        <a-alert
          type="info"
          show-icon
          class="pfd-tasks-help"
          message="¿Cómo se cargan las tareas?"
        >
          <template #description>
            Cada tarea es un paso del protocolo. El campo <strong>Días de diferencia</strong>
            indica cuántos días hay entre la fecha objetivo de la sub-técnica (el "Label fecha
            objetivo" cargado en la técnica) y el momento en que se ejecuta la tarea —
            <strong>0</strong> significa el mismo día. El selector de al lado
            (<strong>Antes / Después</strong>) define la dirección: si la tarea ocurre antes o
            después de esa fecha objetivo. Lo mismo aplica a las alertas de cada tarea, pero
            relativas a la fecha de la tarea en vez de la fecha objetivo.
          </template>
        </a-alert>
        <ProtocolTaskList v-model="tasks" />
      </a-form-item>
    </a-form>

    <template v-if="!hasNoChildren" #footer>
      <a-space style="width: 100%; justify-content: flex-end">
        <BaseButton variant="secondary" html-type="button" @click="emit('cancel')">
          Cancelar
        </BaseButton>
        <BaseButton variant="primary" html-type="button" :loading="loading" @click="onSubmit">
          {{ initialValues ? 'Guardar cambios' : 'Crear protocolo' }}
        </BaseButton>
      </a-space>
    </template>
  </BaseDrawer>
</template>

<style scoped>
.pfd-alert {
  margin-bottom: 16px;
}

.pfd-tasks-help {
  margin-bottom: 12px;
}

.pfd-color-input {
  width: 48px;
  height: 32px;
  padding: 2px;
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
}
</style>
