import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import type { Tutorial } from '../types/tutorial.types'

type ModalType = 'create' | 'edit' | null

export const useTutorialsUiStore = defineStore('tutorials-ui', () => {
  const selectedTutorial = ref<Tutorial | null>(null)
  const activeModal = shallowRef<ModalType>(null)

  function openModal(modal: NonNullable<ModalType>, tutorial?: Tutorial) {
    selectedTutorial.value = tutorial ?? null
    activeModal.value = modal
  }

  function closeModal() {
    activeModal.value = null
    selectedTutorial.value = null
  }

  return { selectedTutorial, activeModal, openModal, closeModal }
})
