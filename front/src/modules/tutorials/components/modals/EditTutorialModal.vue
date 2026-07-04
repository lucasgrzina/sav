<script setup lang="ts">
import { watch } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import TutorialForm from '../forms/TutorialForm.vue'
import { useUpdateTutorial } from '../../composables/useUpdateTutorial'
import type { Tutorial } from '../../types/tutorial.types'
import type { TutorialFormValues } from '../../validators/tutorial.validator'

const props = defineProps<{
  tutorial: Tutorial | null
}>()

const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useUpdateTutorial()

watch(isOpen, (open) => {
  if (open) resetErrors()
})

function handleSubmit(values: TutorialFormValues) {
  if (!props.tutorial) return

  mutate(
    {
      guid: props.tutorial.guid,
      payload: {
        title: values.title,
        description: values.description ?? null,
        source: values.source,
        code: values.code,
        order: values.order,
      },
    },
    {
      onSuccess: () => { isOpen.value = false },
    },
  )
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Editar tutorial" :width="600">
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
      :initial-values="tutorial"
      @submit="handleSubmit"
    >
      <template #cancel>
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
      </template>
    </TutorialForm>
  </BaseModal>
</template>
