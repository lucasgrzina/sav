import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  createHealthPlanTemplateApi,
  updateHealthPlanTemplateApi,
  deleteHealthPlanTemplateApi,
} from '../api/health-plan-templates.api'
import type { CreateHealthPlanTemplatePayload, UpdateHealthPlanTemplatePayload } from '../types/health.types'

export function useCreateHealthPlanTemplate() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateHealthPlanTemplatePayload) => createHealthPlanTemplateApi(payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-templates'] })
      success('Plantilla creada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear la plantilla.'
      if (apiError.message) {
        error('Error al crear la plantilla')
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

export function useUpdateHealthPlanTemplate() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateHealthPlanTemplatePayload }) =>
      updateHealthPlanTemplateApi(guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-templates'] })
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-template', guid] })
      success('Plantilla actualizada correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al actualizar la plantilla.'
      if (apiError.message) {
        error('Error al actualizar la plantilla')
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

export function useDeleteHealthPlanTemplate() {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteHealthPlanTemplateApi(guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-health-plan-templates'] })
      success('Plantilla eliminada correctamente')
    },
    onError: (err: unknown) => {
      const e = err as { message?: string }
      error(e?.message ?? 'Error al eliminar la plantilla')
    },
  })

  return { ...mutation }
}
