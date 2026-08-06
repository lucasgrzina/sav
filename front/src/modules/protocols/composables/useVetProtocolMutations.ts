import { computed, ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  createVetProtocolApi,
  updateVetProtocolApi,
  deleteVetProtocolApi,
} from '../api/vet-protocol.api'
import type {
  CreateVetProtocolPayload,
  UpdateVetProtocolPayload,
  VetProtocolListItem,
} from '../types/vet-protocol.types'
import type { ProtocolDeleteError, ProtocolTechniqueLockedError } from '../types/protocol.types'

// Tipo del error crudo que expone el interceptor HTTP del proyecto
interface RawApiError {
  success: false
  status?: number
  message?: string
  errors?: Record<string, unknown> | null
}

function getRawError(err: unknown): RawApiError {
  return err as RawApiError
}

// --- useCreateVetProtocol ---

export function useCreateVetProtocol() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateVetProtocolPayload) => createVetProtocolApi(vetGuid.value, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vet-protocols', vetGuid.value] })
      success('Protocolo creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el protocolo.'
      if (apiError.message) {
        error('Error al crear el protocolo')
      }
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, resetErrors }
}

// --- useUpdateVetProtocol ---

export function useUpdateVetProtocol() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)
  const techniqueLockedError = ref<ProtocolTechniqueLockedError | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateVetProtocolPayload }) =>
      updateVetProtocolApi(vetGuid.value, guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
      techniqueLockedError.value = null
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['vet-protocols', vetGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['vet-protocol', vetGuid.value, guid] })
      success('Protocolo actualizado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      // Error 422 de negocio: technique_id bloqueado por programas vinculados
      if (raw.status === 422 && raw.errors && raw.errors.reason === 'technique_locked') {
        techniqueLockedError.value = {
          reason: 'technique_locked',
          count: raw.errors.count as number,
        }
        generalError.value = raw.message ?? 'La sub-técnica no puede modificarse.'
      } else {
        const apiError = parseApiError(err)
        fieldErrors.value = apiError.fieldErrors
        generalError.value = apiError.message ?? 'Error al actualizar el protocolo.'
        if (apiError.message) {
          error('Error al actualizar el protocolo')
        }
      }
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    techniqueLockedError.value = null
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, techniqueLockedError, resetErrors }
}

// --- useDeleteVetProtocol ---

export function useDeleteVetProtocol() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const deleteBlockedError = ref<ProtocolDeleteError | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteVetProtocolApi(vetGuid.value, guid),
    onMutate: () => {
      deleteBlockedError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vet-protocols', vetGuid.value] })
      success('Protocolo eliminado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      // Error 422 de negocio: protocolo bloqueado por programas vinculados
      if (raw.status === 422 && raw.errors && 'reason' in raw.errors) {
        deleteBlockedError.value = {
          reason: raw.errors.reason as ProtocolDeleteError['reason'],
          count: raw.errors.count as number,
        }
      } else {
        error('Error al eliminar el protocolo')
      }
    },
  })

  function clearDeleteError() {
    deleteBlockedError.value = null
    mutation.reset()
  }

  return { ...mutation, deleteBlockedError, clearDeleteError }
}

// --- useDeleteVetProtocolWithModal ---
// Wrapper que expone deleteProtocol(item) directo, para uso en la lista

export function useDeleteVetProtocolWithModal() {
  const deleteComposable = useDeleteVetProtocol()
  const selectedProtocol = ref<VetProtocolListItem | null>(null)
  const showDeleteModal = ref(false)

  function openDeleteModal(protocol: VetProtocolListItem) {
    selectedProtocol.value = protocol
    showDeleteModal.value = true
    deleteComposable.clearDeleteError()
  }

  function closeDeleteModal() {
    showDeleteModal.value = false
    selectedProtocol.value = null
    deleteComposable.clearDeleteError()
  }

  function confirmDelete() {
    if (selectedProtocol.value) {
      deleteComposable.mutate(selectedProtocol.value.guid, {
        onSuccess: () => {
          showDeleteModal.value = false
          selectedProtocol.value = null
        },
      })
    }
  }

  return {
    ...deleteComposable,
    selectedProtocol,
    showDeleteModal,
    openDeleteModal,
    closeDeleteModal,
    confirmDelete,
  }
}
