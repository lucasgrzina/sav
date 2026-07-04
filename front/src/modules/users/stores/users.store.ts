import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { UserItem } from '../types/user.types'

type ModalType = 'create' | 'createTenant' | 'edit' | 'delete' | 'changePassword' | 'resetPassword' | null

export const useUsersUiStore = defineStore('users-ui', () => {
  const selectedUser = ref<UserItem | null>(null)
  const activeModal = ref<ModalType>(null)

  function openModal(modal: NonNullable<ModalType>, user?: UserItem) {
    selectedUser.value = user ?? null
    activeModal.value = modal
  }

  function closeModal() {
    activeModal.value = null
    selectedUser.value = null
  }

  return { selectedUser, activeModal, openModal, closeModal }
})
