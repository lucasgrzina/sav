<script setup lang="ts">
import { computed } from 'vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import type { SelectOption } from '@/core/types/ui.types'
import type { HealthPlanTemplate } from '@/modules/health/types/health.types'
import { MONTH_LABELS } from '../../constants/establishment-health-plan.constants'

const props = withDefaults(
  defineProps<{
    clientOptions: SelectOption[]
    establishmentOptions: SelectOption[]
    templateOptions: SelectOption[]
    selectedTemplate: HealthPlanTemplate | null
    loadingClients?: boolean
    loadingEstablishments?: boolean
    loadingTemplates?: boolean
    errors: {
      establishment_id?: string
      health_plan_template_id?: string
      year?: string
    }
  }>(),
  { loadingClients: false, loadingEstablishments: false, loadingTemplates: false },
)

const clientId = defineModel<string>('clientId', { required: true })
const establishmentId = defineModel<string>('establishmentId', { required: true })
const templateId = defineModel<string>('templateId', { required: true })
const year = defineModel<number | undefined>('year', { required: true })

const establishmentSelectValue = computed<string>({
  get: () => (props.loadingEstablishments ? '' : establishmentId.value),
  set: (value) => { establishmentId.value = value },
})

const templateSelectValue = computed<string>({
  get: () => (props.loadingTemplates ? '' : templateId.value),
  set: (value) => { templateId.value = value },
})

function monthsLabel(months: number[]): string {
  return [...months].sort((a, b) => a - b).map((m) => MONTH_LABELS[m - 1]).join(', ')
}
</script>

<template>
  <BaseCard title="Establecimiento y plantilla">
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item label="Cliente" required>
          <BaseSelect
            v-model="clientId"
            :options="clientOptions"
            :loading="loadingClients"
            placeholder="Seleccioná un cliente"
          />
        </a-form-item>
      </a-col>

      <a-col :xs="24" :md="12">
        <a-form-item
          label="Establecimiento"
          :validate-status="errors.establishment_id ? 'error' : ''"
          :help="errors.establishment_id ?? ''"
          required
        >
          <BaseSelect
            v-model="establishmentSelectValue"
            :options="establishmentOptions"
            :disabled="!clientId"
            :loading="loadingEstablishments"
            placeholder="Seleccioná un establecimiento"
          />
        </a-form-item>
      </a-col>
    </a-row>

    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item
          label="Plan sanitario"
          :validate-status="errors.health_plan_template_id ? 'error' : ''"
          :help="errors.health_plan_template_id ?? ''"
          required
        >
          <BaseSelect
            v-model="templateSelectValue"
            :options="templateOptions"
            :loading="loadingTemplates"
            placeholder="Seleccioná una plantilla"
          />
        </a-form-item>
      </a-col>

      <a-col :xs="24" :md="12">
        <a-form-item
          label="Año (ciclo ganadero)"
          :validate-status="errors.year ? 'error' : ''"
          :help="errors.year ?? ''"
          required
        >
          <a-input-number v-model:value="year" style="width: 100%" placeholder="2026" />
        </a-form-item>
      </a-col>
    </a-row>

    <div v-if="selectedTemplate" class="ehpf-preview">
      <span class="ehpf-preview-title">Actividades de la plantilla</span>
      <ul class="ehpf-preview-list">
        <li v-for="activity in selectedTemplate.activities" :key="activity.guid">
          <strong>{{ activity.name }}</strong>: {{ monthsLabel(activity.months) }}
        </li>
      </ul>
    </div>
  </BaseCard>
</template>

<style scoped>
.ehpf-preview {
  margin-top: 8px;
  padding: 12px;
  border-radius: 8px;
  background: rgba(26, 229, 160, 0.04);
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
}

.ehpf-preview-title {
  font-weight: 600;
  font-size: 13px;
}

.ehpf-preview-list {
  margin: 8px 0 0;
  padding-left: 18px;
  font-size: 13px;
}
</style>
