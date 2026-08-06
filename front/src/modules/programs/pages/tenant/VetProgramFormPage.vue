<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ProgramClientSection from '../../components/tenant/form-sections/ProgramClientSection.vue'
import ProgramTechniqueSection from '../../components/tenant/form-sections/ProgramTechniqueSection.vue'
import ProgramGroupsSection from '../../components/tenant/form-sections/ProgramGroupsSection.vue'
import ProgramOtherDataSection from '../../components/tenant/form-sections/ProgramOtherDataSection.vue'
import { useClients } from '@/modules/clients/composables/useClients'
import { useClientEstablishments } from '@/modules/clients/composables/useClientEstablishments'
import { useVetStaff } from '@/modules/vets/composables/useVetStaff'
import { useClientStaff } from '@/modules/clients/composables/useClientStaff'
import { useTechniqueTree } from '@/modules/protocols/composables/useTechniqueTree'
import { useVetProtocolList } from '@/modules/protocols/composables/useVetProtocolList'
import { useAnimalSearch } from '../../composables/useAnimalSearch'
import { useProgramDetail } from '../../composables/useProgramDetail'
import { useCreateProgram, useUpdateProgram } from '../../composables/useProgramMutations'
import { programSchema } from '../../validators/program.validator'
import type { ProgramFormValues } from '../../validators/program.validator'
import type { ProgramDetail, CreateProgramPayload } from '../../types/program.types'

// DEC-11: vista de página completa por secciones (no Drawer/modal). Reemplaza
// ProgramFormDrawer.vue — ver .claude/docs/plans/program-module-plan.md.
const route = useRoute()
const router = useRouter()

const vetGuid = computed(() => route.params.vetGuid as string)
const editingGuid = computed(() => (route.params.guid as string) || null)
const isEditMode = computed(() => Boolean(editingGuid.value))

function backToList() {
  router.push({ name: 'vet-programs-list' })
}

// --- Carga del programa a editar ---

const { data: programDetail, isLoading: isLoadingDetail } = useProgramDetail(editingGuid)

function toFormValues(detail: ProgramDetail | null): ProgramFormValues {
  if (!detail) {
    return {
      client_id: '',
      establishment_id: '',
      protocol_id: '',
      comments: null,
      targets: [{ target_date: '', animals: [] }],
      manager_profile_ids: [],
    }
  }
  return {
    client_id: detail.client.guid,
    establishment_id: detail.establishment.guid,
    protocol_id: detail.protocol.guid,
    comments: detail.comments,
    targets: detail.targets.map((t) => ({
      guid: t.guid,
      target_date: t.target_date,
      animals: t.animals.map((a) => ({ id: a.guid, rp: a.rp })),
    })),
    manager_profile_ids: detail.managers.map((m) => m.guid),
  }
}

const { errors, defineField, handleSubmit, setErrors, resetForm } = useForm<ProgramFormValues>({
  validationSchema: toTypedSchema(programSchema),
  initialValues: toFormValues(null),
})

const [clientId] = defineField('client_id')
const [establishmentId] = defineField('establishment_id')
const [protocolId] = defineField('protocol_id')
const [comments] = defineField('comments')
const [targets] = defineField('targets')
const [managerProfileIds] = defineField('manager_profile_ids')

// Evita que los watchers pensados para cambios manuales del usuario (cliente, técnica) actúen
// mientras se está precargando el form desde un programa existente (resetForm + refs de cascada).
const isResettingForm = ref(false)

// --- Técnica / sub-técnica / protocolo (DEC-13) ---
// El campo de negocio que persiste el programa es `technique_id` (resuelto de `protocol_id`,
// DEC-06) — acá solo se pilotea la cascada de selects con dos refs locales.
const rootTechniqueId = ref('')
const subTechniqueId = ref('')

const { data: techniques, isLoading: isLoadingTechniques } = useTechniqueTree()

function findRootGuidForSubTechnique(subTechniqueGuid: string): string | null {
  const root = (techniques.value ?? []).find((r) => r.children.some((c) => c.guid === subTechniqueGuid))
  return root?.guid ?? null
}

// Carrera entre useProgramDetail y useTechniqueTree: si el árbol termina de cargar después de
// que ya seteamos subTechniqueId (o viceversa), re-resolver la raíz correspondiente.
// isResettingForm se activa acá también: si para este momento el watcher de programDetail ya
// bajó la flag (nextTick), setear rootTechniqueId dispararía el watcher de "cambio manual" de
// abajo y limpiaría subTechniqueId/protocolId justo después de haberlos hidratado.
watch(techniques, () => {
  if (subTechniqueId.value && !rootTechniqueId.value) {
    isResettingForm.value = true
    rootTechniqueId.value = findRootGuidForSubTechnique(subTechniqueId.value) ?? ''
    nextTick(() => { isResettingForm.value = false })
  }
})

watch(
  programDetail,
  (detail) => {
    if (!isEditMode.value) return
    isResettingForm.value = true
    resetForm({ values: toFormValues(detail ?? null) })
    if (detail) {
      subTechniqueId.value = detail.technique.guid
      rootTechniqueId.value = findRootGuidForSubTechnique(detail.technique.guid) ?? ''
    }
    nextTick(() => { isResettingForm.value = false })
  },
  { immediate: true },
)

watch(rootTechniqueId, (newValue, oldValue) => {
  if (isResettingForm.value) return
  if (newValue === oldValue) return
  subTechniqueId.value = ''
  protocolId.value = ''
})

watch(subTechniqueId, (newValue, oldValue) => {
  if (isResettingForm.value) return
  if (newValue === oldValue) return
  protocolId.value = ''
})

const rootTechniqueOptions = computed(() => (techniques.value ?? []).map((t) => ({ value: t.guid, label: t.name })))

const currentRoot = computed(() => (techniques.value ?? []).find((t) => t.guid === rootTechniqueId.value) ?? null)

const subTechniqueOptions = computed(() =>
  (currentRoot.value?.children ?? [])
    .filter((c) => Boolean(c.guid))
    .map((c) => ({ value: c.guid as string, label: c.name })),
)

const resolvedSubTechnique = computed(
  () => currentRoot.value?.children.find((c) => c.guid === subTechniqueId.value) ?? null,
)

// DEC-13: labels dinámicos leídos de la SUB-técnica seleccionada (no de la raíz).
const protocolLabel = computed(() => resolvedSubTechnique.value?.protocols_name ?? 'Protocolo')
const dateLabel = computed(() => resolvedSubTechnique.value?.target_date_name ?? 'Fecha objetivo')

const { data: protocolsResponse, isLoading: isLoadingProtocols } = useVetProtocolList(
  computed(() => ({ technique_id: subTechniqueId.value || undefined, per_page: 100 })),
)
const protocolOptions = computed(
  () => protocolsResponse.value?.data.map((p) => ({ value: p.guid, label: p.name })) ?? [],
)

// --- Cliente / Establecimiento / Responsables (DEC-12/DEC-14) ---

const { data: clientsResponse, isLoading: isLoadingClients } = useClients()
const clientOptions = computed(
  () => clientsResponse.value?.data.map((c) => ({ value: c.guid, label: c.name })) ?? [],
)

const { data: establishments, isLoading: isLoadingEstablishments } = useClientEstablishments(clientId)
const establishmentOptions = computed(
  () => establishments.value?.map((e) => ({ value: e.guid, label: e.name })) ?? [],
)

const { data: vetStaffResponse, isLoading: isLoadingVetStaff } = useVetStaff(vetGuid)
const vetStaffOptions = computed(
  () => vetStaffResponse.value?.map((p) => ({ guid: p.guid, label: p.user.name, role: p.role.name })) ?? [],
)

const { data: clientStaffResponse, isLoading: isLoadingClientStaff } = useClientStaff(vetGuid, clientId)
const clientStaffOptions = computed(
  () => clientStaffResponse.value?.map((p) => ({ guid: p.guid, label: p.user.name, role: p.role.name })) ?? [],
)

// --- Búsqueda de animales (compartida entre todas las filas del repeater, DEC-10) ---

const animalSearch = useAnimalSearch(
  () => vetGuid.value,
  () => clientId.value,
)

function onAnimalSearch(value: string) {
  animalSearch.search(value)
}

// Al cambiar (o limpiar) el cliente: un animal/establecimiento/responsable de cliente
// seleccionado bajo el cliente anterior no tiene sentido bajo el nuevo (DEC-12/DEC-14).
watch(clientId, (newValue, oldValue) => {
  if (isResettingForm.value) return
  if (newValue === oldValue) return
  establishmentId.value = ''
  animalSearch.reset()
  targets.value = targets.value.map((t) => ({ ...t, animals: [] }))
  managerProfileIds.value = managerProfileIds.value.filter((guid) =>
    (vetStaffResponse.value ?? []).some((p) => p.guid === guid),
  )
})

// --- Guardado ---

const { mutate: mutateCreate, isPending: isCreating, fieldErrors: createErrors } = useCreateProgram()
const { mutate: mutateUpdate, isPending: isUpdating, fieldErrors: updateErrors } = useUpdateProgram()

const isSaving = computed(() => isCreating.value || isUpdating.value)
const formFieldErrors = computed(() => (isEditMode.value ? updateErrors.value : createErrors.value))

watch(formFieldErrors, (errs) => setErrors(errs ?? {}))

function toPayload(values: ProgramFormValues): CreateProgramPayload {
  return {
    client_id: values.client_id,
    establishment_id: values.establishment_id,
    protocol_id: values.protocol_id,
    comments: values.comments,
    targets: values.targets.map((t) => ({
      guid: t.guid,
      target_date: t.target_date,
      animals: t.animals.map((a) => ({ id: a.id, rp: a.rp })),
    })),
    manager_profile_ids: values.manager_profile_ids,
  }
}

const onSubmit = handleSubmit((values) => {
  const payload = toPayload(values)

  if (isEditMode.value && editingGuid.value) {
    mutateUpdate({ guid: editingGuid.value, payload }, { onSuccess: () => backToList() })
  } else {
    mutateCreate(payload, { onSuccess: () => backToList() })
  }
})

const title = computed(() => (isEditMode.value ? 'Editar programa' : 'Nuevo programa'))
</script>

<template>
  <div class="vpfp-root">
    <div class="vpfp-header">
      <BaseButton variant="tertiary" @click="backToList">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a programas
      </BaseButton>
    </div>

    <AppHeader :title="title" subtitle="Completá los datos del programa organizados por secciones." />

    <a-spin :spinning="isEditMode && isLoadingDetail">
      <a-form layout="vertical" class="vpfp-form" @submit.prevent="onSubmit">
        <ProgramClientSection
          v-model:client-id="clientId"
          v-model:establishment-id="establishmentId"
          v-model:manager-profile-ids="managerProfileIds"
          :client-options="clientOptions"
          :establishment-options="establishmentOptions"
          :vet-staff-options="vetStaffOptions"
          :client-staff-options="clientStaffOptions"
          :loading-clients="isLoadingClients"
          :loading-establishments="isLoadingEstablishments"
          :loading-vet-staff="isLoadingVetStaff"
          :loading-client-staff="isLoadingClientStaff"
          :errors="{
            client_id: errors.client_id,
            establishment_id: errors.establishment_id,
            manager_profile_ids: errors.manager_profile_ids,
          }"
        />

        <ProgramTechniqueSection
          v-model:root-technique-id="rootTechniqueId"
          v-model:sub-technique-id="subTechniqueId"
          v-model:protocol-id="protocolId"
          :root-technique-options="rootTechniqueOptions"
          :sub-technique-options="subTechniqueOptions"
          :protocol-options="protocolOptions"
          :protocol-label="protocolLabel"
          :loading-techniques="isLoadingTechniques"
          :loading-protocols="isLoadingProtocols"
          :errors="{ technique_id: errors.protocol_id, protocol_id: errors.protocol_id }"
        />

        <ProgramGroupsSection
          v-model="targets"
          :animal-options="animalSearch.options.value"
          :animal-search-loading="animalSearch.loading.value"
          :date-label="dateLabel"
          @search="onAnimalSearch"
        />

        <ProgramOtherDataSection v-model:comments="comments" />

        <div class="vpfp-actions">
          <BaseButton variant="secondary" html-type="button" @click="backToList">
            Cancelar
          </BaseButton>
          <BaseButton variant="primary" html-type="button" :loading="isSaving" @click="onSubmit">
            {{ isEditMode ? 'Guardar cambios' : 'Crear programa' }}
          </BaseButton>
        </div>
      </a-form>
    </a-spin>
  </div>
</template>

<style scoped>
.vpfp-header {
  margin-bottom: 16px;
}

.vpfp-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
}

.vpfp-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 16px;
}
</style>
