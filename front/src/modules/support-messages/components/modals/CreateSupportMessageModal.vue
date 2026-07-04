<script setup lang="ts">
import { watch } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import CreateSupportMessageForm from '../forms/CreateSupportMessageForm.vue'
import { useCreateSupportMessage } from '../../composables/useCreateSupportMessage'
import type { CreateSupportMessagePayload } from '../../types/support-message.types'

const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useCreateSupportMessage()

watch(isOpen, (open) => {
  if (open) resetErrors()
})

function handleSubmit(values: CreateSupportMessagePayload) {
  mutate(values, {
    onSuccess: () => { isOpen.value = false },
  })
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Nuevo mensaje de soporte" :width="600">
    <a-alert
      v-if="generalError && !fieldErrors"
      :message="generalError"
      type="error"
      show-icon
      style="margin-bottom: 16px"
    />
    <CreateSupportMessageForm
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
    >
      <template #cancel>
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
      </template>
    </CreateSupportMessageForm>
  </BaseModal>
</template>
