<script setup lang="ts">
import { watch } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import TutorialForm from '../forms/TutorialForm.vue'
import { useCreateTutorial } from '../../composables/useCreateTutorial'
import type { TutorialFormValues } from '../../validators/tutorial.validator'

const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useCreateTutorial()

watch(isOpen, (open) => {
  if (open) resetErrors()
})

function handleSubmit(values: TutorialFormValues) {
  mutate(
    {
      title: values.title,
      description: values.description ?? null,
      source: values.source,
      code: values.code,
      order: values.order,
    },
    {
      onSuccess: () => { isOpen.value = false },
    },
  )
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Nuevo tutorial" :width="600">
    <a-alert
      v-if="generalError && !fieldErrors"
      :message="generalError"
      type="error"
      show-icon
      style="margin-bottom: 16px"
    />
    <TutorialForm
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
    >
      <template #cancel>
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
      </template>
    </TutorialForm>
  </BaseModal>
</template>
