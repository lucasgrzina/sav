import { computed, ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import {
  createEstablishmentHealthPlanApi,
  cancelEstablishmentHealthPlanApi,
  confirmEstablishmentHealthPlanActivityApi,
} from '../api/establishment-health-plans.api'
import type {
  CreateEstablishmentHealthPlanPayload,
  EstablishmentHealthPlanCancelTarget,
} from '../types/establishment-health-plan.types'

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

// --- useCreateEstablishmentHealthPlan ---

export function useCreateEstablishmentHealthPlan() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateEstablishmentHealthPlanPayload) =>
      createEstablishmentHealthPlanApi(vetGuid.value, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['establishment-health-plans', vetGuid.value] })
      success('Plan sanitario creado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422 && raw.errors && raw.errors.reason === 'duplicate_plan') {
        generalError.value = 'Ya existe un plan activo para este establecimiento, plantilla y año.'
        error(generalError.value)
        return
      }
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el plan sanitario.'
      if (apiError.message) {
        error('Error al crear el plan sanitario')
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

// --- useCancelEstablishmentHealthPlan ---

export function useCancelEstablishmentHealthPlan() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => cancelEstablishmentHealthPlanApi(vetGuid.value, guid),
    onSuccess: (_, guid) => {
      queryClient.invalidateQueries({ queryKey: ['establishment-health-plans', vetGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['establishment-health-plan', vetGuid.value, guid] })
      success('Plan sanitario cancelado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422 && raw.errors && raw.errors.reason === 'not_editable') {
        error('El plan sanitario ya estaba cancelado.')
      } else {
        error('Error al cancelar el plan sanitario')
      }
    },
  })

  return { ...mutation }
}

// --- useCancelEstablishmentHealthPlanWithModal ---
// Wrapper que expone cancelPlan(item) directo, para uso en la lista

export function useCancelEstablishmentHealthPlanWithModal() {
  const cancelComposable = useCancelEstablishmentHealthPlan()
  const selectedPlan = ref<EstablishmentHealthPlanCancelTarget | null>(null)
  const showCancelModal = ref(false)

  function openCancelModal(plan: EstablishmentHealthPlanCancelTarget) {
    selectedPlan.value = plan
    showCancelModal.value = true
  }

  function closeCancelModal() {
    showCancelModal.value = false
    selectedPlan.value = null
  }

  function confirmCancel() {
    if (selectedPlan.value) {
      cancelComposable.mutate(selectedPlan.value.guid, {
        onSuccess: () => {
          showCancelModal.value = false
          selectedPlan.value = null
        },
      })
    }
  }

  return {
    ...cancelComposable,
    selectedPlan,
    showCancelModal,
    openCancelModal,
    closeCancelModal,
    confirmCancel,
  }
}

// --- useConfirmEstablishmentHealthPlanActivity ---

export function useConfirmEstablishmentHealthPlanActivity() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: ({ planGuid, activityGuid }: { planGuid: string; activityGuid: string }) =>
      confirmEstablishmentHealthPlanActivityApi(vetGuid.value, planGuid, activityGuid),
    onSuccess: (_, { planGuid }) => {
      // Invalida solo el detalle del plan (refresca el estado de la fila), no la lista completa.
      queryClient.invalidateQueries({ queryKey: ['establishment-health-plan', vetGuid.value, planGuid] })
      success('Actividad confirmada correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 403 && raw.errors && raw.errors.reason === 'role_not_allowed') {
        error('Tu rol no está habilitado para confirmar esta actividad.')
      } else {
        error('Error al confirmar la actividad')
      }
    },
  })

  return { ...mutation }
}
