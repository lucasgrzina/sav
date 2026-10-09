import { computed, ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  createHealthPlanTemplateCatalogApi,
  updateHealthPlanTemplateCatalogApi,
  deleteHealthPlanTemplateCatalogApi,
} from '../api/health-plan-templates-catalog.api'
import type {
  CreateHealthPlanTemplatePayload,
  UpdateHealthPlanTemplatePayload,
} from '@/modules/health/types/health.types'

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

export function useCreateHealthPlanTemplateCatalog() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateHealthPlanTemplatePayload) =>
      createHealthPlanTemplateCatalogApi(vetGuid.value, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['health-plan-templates-catalog', vetGuid.value] })
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

export function useUpdateHealthPlanTemplateCatalog() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateHealthPlanTemplatePayload }) =>
      updateHealthPlanTemplateCatalogApi(vetGuid.value, guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['health-plan-templates-catalog', vetGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['health-plan-template-catalog', vetGuid.value, guid] })
      success('Plantilla actualizada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422 && raw.errors && raw.errors.reason === 'template_locked') {
        generalError.value = 'La plantilla ya generó planes sanitarios instanciados y no puede editarse.'
        error(generalError.value)
        return
      }
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

export function useDeleteHealthPlanTemplateCatalog() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => deleteHealthPlanTemplateCatalogApi(vetGuid.value, guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['health-plan-templates-catalog', vetGuid.value] })
      success('Plantilla eliminada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422 && raw.errors && raw.errors.reason === 'template_locked') {
        error('La plantilla ya generó planes sanitarios instanciados y no puede eliminarse.')
      } else {
        error('Error al eliminar la plantilla')
      }
    },
  })

  return { ...mutation }
}
