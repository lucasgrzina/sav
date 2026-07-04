import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  createHealthPlanCategoryApi,
  updateHealthPlanCategoryApi,
  deleteHealthPlanCategoryApi,
} from '../api/health-plan-categories.api'
import type { CreateHealthPlanCategoryPayload, UpdateHealthPlanCategoryPayload } from '../types/health.types'

interface RawApiError {
  success: false
  status?: number
  message?: string
  errors?: Record<string, unknown> | null
}

function getRawError(err: unknown): RawApiError {
  return err as RawApiError
}

export function useCreateHealthPlanCategory() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateHealthPlanCategoryPayload) => createHealthPlanCategoryApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-categories'] })
      success('Categoría creada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear la categoría.'
      if (apiError.message) {
        error('Error al crear la categoría')
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

export function useUpdateHealthPlanCategory() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateHealthPlanCategoryPayload }) =>
      updateHealthPlanCategoryApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-categories'] })
      success('Categoría actualizada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar la categoría.'
      if (apiError.message) {
        error('Error al actualizar la categoría')
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

export function useDeleteHealthPlanCategory() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const blockedMessage = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteHealthPlanCategoryApi(guid),
    onMutate: () => {
      blockedMessage.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-categories'] })
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-templates'] })
      success('Categoría eliminada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422) {
        blockedMessage.value = raw.message ?? 'No se puede eliminar la categoría.'
      } else {
        error(raw.message ?? 'Error al eliminar la categoría')
      }
    },
  })

  function clearBlockedMessage() {
    blockedMessage.value = null
    mutation.reset()
  }

  return { ...mutation, blockedMessage, clearBlockedMessage }
}
