import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  createHealthActivityApi,
  updateHealthActivityApi,
  deleteHealthActivityApi,
} from '../api/health-activities.api'
import type { CreateHealthActivityPayload, UpdateHealthActivityPayload } from '../types/health.types'

interface RawApiError {
  success: false
  status?: number
  message?: string
  errors?: Record<string, unknown> | null
}

function getRawError(err: unknown): RawApiError {
  return err as RawApiError
}

export function useCreateHealthActivity() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateHealthActivityPayload) => createHealthActivityApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-activities'] })
      success('Actividad creada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear la actividad.'
      if (apiError.message) {
        error('Error al crear la actividad')
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

export function useUpdateHealthActivity() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateHealthActivityPayload }) =>
      updateHealthActivityApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-activities'] })
      success('Actividad actualizada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar la actividad.'
      if (apiError.message) {
        error('Error al actualizar la actividad')
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

export function useDeleteHealthActivity() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const blockedMessage = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteHealthActivityApi(guid),
    onMutate: () => {
      blockedMessage.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-activities'] })
      success('Actividad eliminada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422) {
        blockedMessage.value = raw.message ?? 'No se puede eliminar la actividad.'
      } else {
        error(raw.message ?? 'Error al eliminar la actividad')
      }
    },
  })

  function clearBlockedMessage() {
    blockedMessage.value = null
    mutation.reset()
  }

  return { ...mutation, blockedMessage, clearBlockedMessage }
}
