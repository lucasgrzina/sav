<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import EstablishmentHealthPlanForm from '../../components/tenant/EstablishmentHealthPlanForm.vue'
import { useClients } from '@/modules/clients/composables/useClients'
import { useClientEstablishments } from '@/modules/clients/composables/useClientEstablishments'
import {
  useHealthPlanTemplateCatalog,
  useHealthPlanTemplateCatalogDetail,
} from '../../composables/useHealthPlanTemplateCatalog'
import { useCreateEstablishmentHealthPlan } from '../../composables/useEstablishmentHealthPlanMutations'
import { establishmentHealthPlanSchema } from '../../validators/establishment-health-plan.validator'
import type { EstablishmentHealthPlanFormValues } from '../../validators/establishment-health-plan.validator'
import type { CreateEstablishmentHealthPlanPayload } from '../../types/establishment-health-plan.types'

const router = useRouter()

function backToList() {
  router.push({ name: 'vet-health-plans-list' })
}

const { errors, defineField, handleSubmit, setErrors } = useForm<EstablishmentHealthPlanFormValues>({
  validationSchema: toTypedSchema(establishmentHealthPlanSchema),
  initialValues: {
    establishment_id: '',
    health_plan_template_id: '',
    year: new Date().getFullYear(),
  },
})

const [establishmentId] = defineField('establishment_id')
const [templateId] = defineField('health_plan_template_id')
const [year] = defineField('year')

// clientId es solo estado local de UI para filtrar establecimientos — no forma parte del payload.
const clientId = ref('')

const { data: clientsResponse, isLoading: isLoadingClients } = useClients()
const clientOptions = computed(
  () => clientsResponse.value?.data.map((c) => ({ value: c.guid, label: c.name })) ?? [],
)

const { data: establishments, isLoading: isLoadingEstablishments } = useClientEstablishments(clientId)
const establishmentOptions = computed(
  () => establishments.value?.map((e) => ({ value: e.guid, label: e.name })) ?? [],
)

watch(clientId, (newValue, oldValue) => {
  if (newValue === oldValue) return
  establishmentId.value = ''
})

const { data: templatesResponse, isLoading: isLoadingTemplates } = useHealthPlanTemplateCatalog({ per_page: 100 })
const templateOptions = computed(
  () => templatesResponse.value?.data.map((t) => ({
    value: t.guid,
    label: `${t.name} — ${t.is_own ? 'Mi plantilla' : 'Plantilla del sistema'}`,
  })) ?? [],
)

const { data: selectedTemplate } = useHealthPlanTemplateCatalogDetail(templateId)

const { mutate: mutateCreate, isPending: isCreating, fieldErrors } = useCreateEstablishmentHealthPlan()

watch(fieldErrors, (errs) => setErrors(errs ?? {}))

function toPayload(values: EstablishmentHealthPlanFormValues): CreateEstablishmentHealthPlanPayload {
  return {
    establishment_id: values.establishment_id,
    health_plan_template_id: values.health_plan_template_id,
    year: values.year,
  }
}

const onSubmit = handleSubmit((values) => {
  mutateCreate(toPayload(values), {
    onSuccess: (plan) => {
      router.push({ name: 'vet-health-plans-detail', params: { guid: plan.guid } })
    },
  })
})
</script>

<template>
  <div class="vehpnp-root">
    <div class="vehpnp-header">
      <BaseButton variant="tertiary" @click="backToList">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a planes sanitarios
      </BaseButton>
    </div>

    <AppHeader title="Nuevo plan sanitario" subtitle="Instanciá una plantilla del catálogo sobre un establecimiento." />

    <a-form layout="vertical" class="vehpnp-form" @submit.prevent="onSubmit">
      <EstablishmentHealthPlanForm
        v-model:client-id="clientId"
        v-model:establishment-id="establishmentId"
        v-model:template-id="templateId"
        v-model:year="year"
        :client-options="clientOptions"
        :establishment-options="establishmentOptions"
        :template-options="templateOptions"
        :selected-template="selectedTemplate ?? null"
        :loading-clients="isLoadingClients"
        :loading-establishments="isLoadingEstablishments"
        :loading-templates="isLoadingTemplates"
        :errors="{
          establishment_id: errors.establishment_id,
          health_plan_template_id: errors.health_plan_template_id,
          year: errors.year,
        }"
      />

      <div class="vehpnp-actions">
        <BaseButton variant="secondary" html-type="button" @click="backToList">
          Cancelar
        </BaseButton>
        <BaseButton variant="primary" html-type="button" :loading="isCreating" @click="onSubmit">
          Crear plan
        </BaseButton>
      </div>
    </a-form>
  </div>
</template>

<style scoped>
.vehpnp-header {
  margin-bottom: 16px;
}

.vehpnp-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
}

.vehpnp-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 16px;
}
</style>
