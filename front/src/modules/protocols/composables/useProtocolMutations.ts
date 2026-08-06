import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  adminCreateProtocolApi,
  adminUpdateProtocolApi,
  adminDeleteProtocolApi,
  adminReplicateProtocolApi,
} from '../api/protocol.api'
import type {
  CreateProtocolPayload,
  UpdateProtocolPayload,
  ProtocolDeleteError,
  ProtocolTechniqueLockedError,
  ProtocolListItem,
} from '../types/protocol.types'

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

// --- useCreateProtocol ---

export function useCreateProtocol() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateProtocolPayload) => adminCreateProtocolApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-protocols'] })
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

// --- useUpdateProtocol ---

export function useUpdateProtocol() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)
  const techniqueLockedError = ref<ProtocolTechniqueLockedError | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateProtocolPayload }) =>
      adminUpdateProtocolApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
      techniqueLockedError.value = null
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['admin-protocols'] })
      queryClient.invalidateQueries({ queryKey: ['admin-protocol', guid] })
      success('Protocolo actualizado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      // Error 422 de negocio: technique_id bloqueado por programas vinculados (DU-01)
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

// --- useDeleteProtocol ---

export function useDeleteProtocol() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const deleteBlockedError = ref<ProtocolDeleteError | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => adminDeleteProtocolApi(guid),
    onMutate: () => {
      deleteBlockedError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-protocols'] })
      success('Protocolo eliminado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      // Error 422 de negocio: protocolo bloqueado por programas vinculados (RF-04)
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

// --- useDeleteProtocolWithModal ---
// Wrapper que expone deleteProtocol(item) directo, para uso en la lista

export function useDeleteProtocolWithModal() {
  const deleteComposable = useDeleteProtocol()
  const selectedProtocol = ref<ProtocolListItem | null>(null)
  const showDeleteModal = ref(false)

  function openDeleteModal(protocol: ProtocolListItem) {
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

// --- useReplicateProtocol ---

export function useReplicateProtocol() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => adminReplicateProtocolApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-protocols'] })
      success('Protocolo replicado correctamente')
    },
    onError: () => {
      error('Error al replicar el protocolo')
    },
  })

  return { ...mutation }
}
