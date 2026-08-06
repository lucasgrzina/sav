<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseInput from '@/components/atoms/inputs/BaseInput.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import ProtocolTaskList from '../ProtocolTaskList.vue'
import { useTechniqueTree } from '../../composables/useTechniqueTree'
import { protocolSchema } from '../../validators/protocol.validator'
import type { ProtocolFormValues } from '../../validators/protocol.validator'
import type { VetProtocolDetail } from '../../types/vet-protocol.types'

// DEC-11: este drawer es propio del panel vet — NO reutiliza ProtocolFormDrawer.vue
// (ese vive acoplado a una técnica raíz con hijos, embebido en el tab de una técnica).
// Acá el vet elige la técnica desde cero mediante un selector de dos niveles
// (técnica raíz -> sub-técnica), alimentado por GET /v1/techniques (useTechniqueTree).
const props = withDefaults(
  defineProps<{
    loading?: boolean
    fieldErrors?: Record<string, string> | null
    initialValues?: VetProtocolDetail | null
    versionSource?: VetProtocolDetail | null
  }>(),
  { loading: false, initialValues: null, versionSource: null },
)

const emit = defineEmits<{
  submit: [values: ProtocolFormValues]
  cancel: []
}>()

const open = defineModel<boolean>('open', { default: false })

const { data: techniques, isLoading: isLoadingTechniques } = useTechniqueTree()

// "Crear versión" precarga el form completo y editable con los datos del protocolo
// origen (global o propio) — al guardar se crea un protocolo NUEVO (mismo submit que
// un create normal), nunca un update del origen. Los guid de tareas/alertas del origen
// se omiten en el payload (toFormValues con stripGuids) para que el backend los trate
// como filas nuevas.
const isVersionMode = computed(() => Boolean(props.versionSource))
const isEditMode = computed(() => Boolean(props.initialValues) && !isVersionMode.value)

function toFormValues(detail: VetProtocolDetail | null, stripGuids = false): ProtocolFormValues {
  if (!detail) {
    return { technique_id: '', name: '', color: null, country_id: null, tasks: [] }
  }
  return {
    technique_id: detail.technique.guid,
    name: detail.name,
    color: detail.color,
    country_id: null, // DEC-07: no aplica a protocolos de vet
    tasks: detail.tasks.map((t) => ({
      guid: stripGuids ? undefined : t.guid,
      description: t.description,
      days_offset: t.days_offset,
      time_of_day: t.time_of_day,
      time: t.time,
      important: t.important,
      alerts: t.alerts.map((a) => ({
        guid: stripGuids ? undefined : a.guid,
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

function toFormValuesFromProps(): ProtocolFormValues {
  if (props.versionSource) return toFormValues(props.versionSource, true)
  return toFormValues(props.initialValues)
}

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm<ProtocolFormValues>({
  validationSchema: toTypedSchema(protocolSchema),
  initialValues: toFormValuesFromProps(),
})

const [name, nameAttrs] = defineField('name')
const [color, colorAttrs] = defineField('color')
const [techniqueId] = defineField('technique_id')
const [tasks] = defineField('tasks')

const rootTechniqueId = ref<string>('')

function findRootGuidForTechnique(techniqueGuid: string): string | null {
  const root = (techniques.value ?? []).find((r) =>
    r.children.some((c) => c.guid === techniqueGuid),
  )
  return root?.guid ?? null
}

function syncRootFromTechniqueId(techniqueGuid: string) {
  rootTechniqueId.value = techniqueGuid ? findRootGuidForTechnique(techniqueGuid) ?? '' : ''
}

watch(
  [() => props.initialValues, () => props.versionSource],
  () => {
    const values = toFormValuesFromProps()
    resetForm({ values })
    syncRootFromTechniqueId(values.technique_id)
  },
  { immediate: true },
)

watch(techniques, () => {
  syncRootFromTechniqueId(techniqueId.value)
})

const rootTechniqueOptions = computed(() =>
  (techniques.value ?? []).map((t) => ({ value: t.guid, label: t.name })),
)

const currentRoot = computed(
  () => (techniques.value ?? []).find((t) => t.guid === rootTechniqueId.value) ?? null,
)

const subTechniqueOptions = computed(() =>
  (currentRoot.value?.children ?? [])
    .filter((c) => Boolean(c.guid))
    .map((c) => ({ value: c.guid as string, label: c.name })),
)

function onRootTechniqueSelect(value: string | number | null) {
  rootTechniqueId.value = (value as string) ?? ''
  techniqueId.value = ''
}

function onSubTechniqueSelect(value: string | number | null) {
  techniqueId.value = (value as string) ?? ''
}

const onSubmit = handleSubmit((values) => emit('submit', values))

watch(
  () => props.fieldErrors,
  (errs) => setErrors(errs ?? {}),
)

const title = computed(() => {
  if (isVersionMode.value) return 'Crear versión de protocolo'
  return isEditMode.value ? 'Editar protocolo' : 'Nuevo protocolo'
})
</script>

<template>
  <BaseDrawer v-model="open" :title="title" :width="560" @close="emit('cancel')">
    <a-alert
      v-if="isVersionMode"
      type="info"
      show-icon
      message="Se creará un protocolo nuevo a partir de esta base"
      class="vpfd-alert"
    >
      <template #description>
        El formulario viene precargado con los datos de <strong>{{ versionSource?.name }}</strong>
        ({{ versionSource?.technique.name }}). Podés editar cualquier campo antes de guardar — al
        confirmar se crea un protocolo NUEVO, el original no se modifica.
      </template>
    </a-alert>

    <a-form layout="vertical" @submit.prevent="onSubmit">
      <a-form-item label="Técnica" required>
        <BaseSelect
          :model-value="rootTechniqueId"
          :options="rootTechniqueOptions"
          :loading="isLoadingTechniques"
          :disabled="isVersionMode"
          placeholder="Seleccioná una técnica"
          @update:model-value="onRootTechniqueSelect"
        />
      </a-form-item>

      <a-form-item
        label="Sub-técnica"
        :validate-status="errors.technique_id ? 'error' : ''"
        :help="errors.technique_id ?? ''"
        required
      >
        <BaseSelect
          :model-value="techniqueId"
          :options="subTechniqueOptions"
          :disabled="isVersionMode || !rootTechniqueId"
          placeholder="Seleccioná una sub-técnica"
          @update:model-value="onSubTechniqueSelect"
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
          class="vpfd-color-input"
          @input="(e) => (color = (e.target as HTMLInputElement).value)"
        />
      </a-form-item>

      <a-form-item label="Tareas">
        <ProtocolTaskList v-model="tasks" />
      </a-form-item>
    </a-form>

    <template #footer>
      <a-space style="width: 100%; justify-content: flex-end">
        <BaseButton variant="secondary" html-type="button" @click="emit('cancel')">
          Cancelar
        </BaseButton>
        <BaseButton variant="primary" html-type="button" :loading="loading" @click="onSubmit">
          <template v-if="isVersionMode">Crear versión</template>
          <template v-else>{{ isEditMode ? 'Guardar cambios' : 'Crear protocolo' }}</template>
        </BaseButton>
      </a-space>
    </template>
  </BaseDrawer>
</template>

<style scoped>
.vpfd-alert {
  margin-bottom: 16px;
}

.vpfd-color-input {
  width: 48px;
  height: 32px;
  padding: 2px;
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
}
</style>
