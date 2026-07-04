import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  adminCreateTechniqueApi,
  adminUpdateTechniqueApi,
  adminDeleteTechniqueApi,
} from '../api/technique.api'
import type {
  CreateTechniquePayload,
  UpdateTechniquePayload,
  TechniqueDeleteError,
  TechniqueChildConflict,
  TechniqueListItem,
} from '../types/technique.types'

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

// --- useCreateTechnique ---

export function useCreateTechnique() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateTechniquePayload) => adminCreateTechniqueApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-techniques'] })
      success('Técnica creada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear la técnica.'
      if (apiError.message) {
        error('Error al crear la técnica')
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

// --- useUpdateTechnique ---

export function useUpdateTechnique() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)
  const childConflicts = ref<TechniqueChildConflict[]>([])

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateTechniquePayload }) =>
      adminUpdateTechniqueApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
      childConflicts.value = []
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['admin-techniques'] })
      queryClient.invalidateQueries({ queryKey: ['admin-technique', guid] })
      success('Técnica actualizada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      // Error 422 de negocio: hijos con programas vinculados → errors.conflicts
      if (raw.status === 422 && raw.errors && 'conflicts' in raw.errors) {
        childConflicts.value = raw.errors.conflicts as TechniqueChildConflict[]
        generalError.value = raw.message ?? 'Hay sub-técnicas con programas vinculados.'
      } else {
        const apiError = parseApiError(err)
        fieldErrors.value = apiError.fieldErrors
        generalError.value = apiError.message ?? 'Error al actualizar la técnica.'
        if (apiError.message) {
          error('Error al actualizar la técnica')
        }
      }
    },
  })

  function resetErrors() {
    fieldErrors.value = null
    generalError.value = null
    childConflicts.value = []
    mutation.reset()
  }

  return { ...mutation, fieldErrors, generalError, childConflicts, resetErrors }
}

// --- useDeleteTechnique ---

export function useDeleteTechnique() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const deleteBlockedError = ref<TechniqueDeleteError | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => adminDeleteTechniqueApi(guid),
    onMutate: () => {
      deleteBlockedError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-techniques'] })
      success('Técnica eliminada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      // Error 422 de negocio: técnica bloqueada → errors.reason + errors.count
      if (raw.status === 422 && raw.errors && 'reason' in raw.errors) {
        deleteBlockedError.value = {
          reason: raw.errors.reason as TechniqueDeleteError['reason'],
          count: raw.errors.count as number,
        }
      } else {
        error('Error al eliminar la técnica')
      }
    },
  })

  function clearDeleteError() {
    deleteBlockedError.value = null
    mutation.reset()
  }

  return { ...mutation, deleteBlockedError, clearDeleteError }
}

// --- useDeleteTechniqueWithModal ---
// Wrapper que expone deleteTechnique(item) directo, para uso en la lista

export function useDeleteTechniqueWithModal() {
  const deleteComposable = useDeleteTechnique()
  const selectedTechnique = ref<TechniqueListItem | null>(null)
  const showDeleteModal = ref(false)

  function openDeleteModal(technique: TechniqueListItem) {
    selectedTechnique.value = technique
    showDeleteModal.value = true
    deleteComposable.clearDeleteError()
  }

  function closeDeleteModal() {
    showDeleteModal.value = false
    selectedTechnique.value = null
    deleteComposable.clearDeleteError()
  }

  function confirmDelete() {
    if (selectedTechnique.value) {
      deleteComposable.mutate(selectedTechnique.value.guid, {
        onSuccess: () => {
          showDeleteModal.value = false
          selectedTechnique.value = null
        },
      })
    }
  }

  return {
    ...deleteComposable,
    selectedTechnique,
    showDeleteModal,
    openDeleteModal,
    closeDeleteModal,
    confirmDelete,
  }
}
