<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import TechniqueForm from '../components/TechniqueForm.vue'
import { useCreateTechnique } from '../composables/useTechniqueMutations'
import type { TechniqueFormValues } from '../validators/technique.validator'

const router = useRouter()
const { mutate, isPending, fieldErrors, generalError } = useCreateTechnique()

function handleSubmit(values: TechniqueFormValues) {
  mutate(
    {
      name: values.name,
      type: values.type,
      target_date_name: values.target_date_name ?? null,
      protocols_name: values.protocols_name ?? null,
      children: (values.children ?? []).map((c) => ({
        name: c.name,
        protocols_name: c.protocols_name ?? null,
      })),
    },
    {
      onSuccess: (technique) => {
        router.push(`/techniques/${technique.guid}`)
      },
    },
  )
}
</script>

<template>
  <div>
    <div class="tcp-back">
      <BaseButton variant="tertiary" @click="router.push('/techniques')">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a técnicas
      </BaseButton>
    </div>

    <AppHeader
      title="Nueva Técnica"
      subtitle="Creá una técnica de reproducción o vacuna con sus sub-técnicas"
      size="default"
    />

    <div v-if="generalError && !fieldErrors" class="tcp-error-alert">
      {{ generalError }}
    </div>

    <TechniqueForm
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
      @cancel="router.push('/techniques')"
    />
  </div>
</template>

<style scoped>
.tcp-back {
  margin-bottom: 16px;
}

.tcp-error-alert {
  padding: 12px 16px;
  border-radius: 10px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 20px;
}
</style>
