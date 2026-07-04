<script setup lang="ts">
import { watch } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import UserForm from '../forms/UserForm.vue'
import { useCreateUser } from '../../composables/useCreateUser'
import type { UserCreatePayload, UserUpdatePayload } from '../../types/user.types'

const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useCreateUser()

watch(isOpen, (open) => {
  if (open) resetErrors()
})

function handleSubmit(values: UserCreatePayload | UserUpdatePayload) {
  mutate(values as UserCreatePayload, {
    onSuccess: () => {
      isOpen.value = false
    },
  })
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Nuevo usuario" :width="600">
    <a-alert
      v-if="generalError && !fieldErrors"
      :message="generalError"
      type="error"
      show-icon
      style="margin-bottom: 16px"
    />
    <UserForm
      mode="create"
      role-type="platform"
      :loading="isPending"
      :field-errors="fieldErrors"
      @submit="handleSubmit"
    >
      <template #cancel>
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
      </template>
    </UserForm>
  </BaseModal>
</template>
