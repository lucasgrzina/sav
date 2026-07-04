<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import TechniqueForm from '../components/TechniqueForm.vue'
import { useTechniqueDetail } from '../composables/useTechniqueDetail'
import { useUpdateTechnique } from '../composables/useTechniqueMutations'
import type { TechniqueFormValues } from '../validators/technique.validator'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { data, isLoading } = useTechniqueDetail(computed(() => props.guid))
const { mutate, isPending, fieldErrors, generalError, childConflicts } = useUpdateTechnique()

const technique = computed(() => data.value?.technique ?? null)

function handleSubmit(values: TechniqueFormValues) {
  mutate(
    {
      guid: props.guid,
      payload: {
        name: values.name,
        type: values.type,
        target_date_name: values.target_date_name ?? null,
        protocols_name: values.protocols_name ?? null,
        children: (values.children ?? []).map((c) => ({
          guid: c.guid,
          name: c.name,
          protocols_name: c.protocols_name ?? null,
        })),
      },
    },
    {
      onSuccess: () => {
        router.push(`/techniques/${props.guid}`)
      },
    },
  )
}
</script>

<template>
  <div>
    <div class="tep-back">
      <BaseButton variant="tertiary" @click="router.push(`/techniques/${props.guid}`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver al detalle
      </BaseButton>
    </div>

    <AppHeader title="Editar Técnica" size="default" />

    <div v-if="isLoading" class="tep-loading">
      Cargando técnica...
    </div>

    <template v-else>
      <div v-if="generalError && !fieldErrors && childConflicts.length === 0" class="tep-error-alert">
        {{ generalError }}
      </div>

      <div v-if="childConflicts.length > 0" class="tep-conflicts-alert">
        <strong>Las siguientes sub-técnicas tienen programas vinculados y no pueden eliminarse:</strong>
        <ul class="tep-conflicts-list">
          <li v-for="c in childConflicts" :key="c.guid">
            {{ c.name }} ({{ c.programs_count }} programa(s))
          </li>
        </ul>
      </div>

      <TechniqueForm
        :initial-values="technique"
        :loading="isPending"
        :field-errors="fieldErrors"
        @submit="handleSubmit"
        @cancel="router.push(`/techniques/${props.guid}`)"
      />
    </template>
  </div>
</template>

<style scoped>
.tep-back {
  margin-bottom: 16px;
}

.tep-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.tep-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}

.tep-conflicts-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 180, 0, 0.3);
  background: rgba(255, 180, 0, 0.08);
  color: #b8860b;
  font-size: 13px;
  margin-bottom: 20px;
}

.tep-conflicts-list {
  margin: 8px 0 0 0;
  padding-left: 20px;
}
</style>
