<script setup lang="ts">
import { watch } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ChangePasswordForm from '../forms/ChangePasswordForm.vue'
import { useChangePassword } from '../../composables/useChangePassword'
import type { UserItem, ChangePasswordPayload } from '../../types/user.types'

const props = defineProps<{ user: UserItem | null }>()
const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useChangePassword()

watch(isOpen, (open) => {
  if (open) resetErrors()
})

function handleSubmit(values: ChangePasswordPayload) {
  if (!props.user?.guid) return
  mutate(
    { guid: props.user.guid, payload: values },
    {
      onSuccess: () => {
        isOpen.value = false
      },
    },
  )
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Cambiar contraseña" :width="500">
    <a-alert
      v-if="generalError && !fieldErrors"
      :message="generalError"
      type="error"
      show-icon
      style="margin-bottom: 16px"
    />
    <ChangePasswordForm
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
    >
      <template #cancel>
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
      </template>
    </ChangePasswordForm>
  </BaseModal>
</template>
