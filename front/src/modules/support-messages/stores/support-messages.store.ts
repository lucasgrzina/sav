import { defineStore } from 'pinia'
import { reactive, ref, watch } from 'vue'
import { useTablePageSize } from '@/modules/settings/composables/useTablePageSize'
import type { SupportMessage, SupportMessageListParams } from '../types/support-message.types'

type ModalType = 'create' | 'detail' | null

export const useSupportMessagesUiStore = defineStore('support-messages-ui', () => {
  const { perPage: storedPerPage, setPerPage } = useTablePageSize('support-messages', 15)

  const filters = reactive<SupportMessageListParams>({
    page: 1,
    per_page: storedPerPage.value,
  })

  watch(() => filters.per_page, (size) => {
    if (size !== undefined) setPerPage(size)
  })

  const selectedMessage = ref<SupportMessage | null>(null)
  const activeModal = ref<ModalType>(null)

  function openModal(modal: NonNullable<ModalType>, message?: SupportMessage) {
    selectedMessage.value = message ?? null
    activeModal.value = modal
  }

  function closeModal() {
    activeModal.value = null
    selectedMessage.value = null
  }

  return { filters, selectedMessage, activeModal, openModal, closeModal }
})
