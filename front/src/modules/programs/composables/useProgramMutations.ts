import { computed, ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import { createProgramApi, updateProgramApi, cancelProgramApi } from '../api/program.api'
import type { CreateProgramPayload, UpdateProgramPayload, ProgramCancelTarget } from '../types/program.types'

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

// --- useCreateProgram ---

export function useCreateProgram() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: (payload: CreateProgramPayload) => createProgramApi(vetGuid.value, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['programs', vetGuid.value] })
      success('Programa creado correctamente')
    },
    onError: (err: unknown) => {
      const apiError = parseApiError(err)
      fieldErrors.value = apiError.fieldErrors
      generalError.value = apiError.message ?? 'Error al crear el programa.'
      if (apiError.message) {
        error('Error al crear el programa')
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

// --- useUpdateProgram ---

export function useUpdateProgram() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()
  const fieldErrors = ref<Record<string, string> | null>(null)
  const generalError = ref<string | null>(null)

  const mutation = useMutation({
    mutationFn: ({ guid, payload }: { guid: string; payload: UpdateProgramPayload }) =>
      updateProgramApi(vetGuid.value, guid, payload),
    onMutate: () => {
      fieldErrors.value = null
      generalError.value = null
    },
    onSuccess: (_, { guid }) => {
      queryClient.invalidateQueries({ queryKey: ['programs', vetGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['program', vetGuid.value, guid] })
      success('Programa actualizado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422 && raw.errors && (raw.errors.reason === 'not_editable' || raw.errors.reason === 'must_have_one_target')) {
        generalError.value =
          raw.errors.reason === 'not_editable'
            ? 'El programa está cancelado y no puede editarse.'
            : 'El programa debe tener al menos un objetivo.'
        error(generalError.value)
      } else {
        const apiError = parseApiError(err)
        fieldErrors.value = apiError.fieldErrors
        generalError.value = apiError.message ?? 'Error al actualizar el programa.'
        if (apiError.message) {
          error('Error al actualizar el programa')
        }
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

// --- useCancelProgram ---

export function useCancelProgram() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const mutation = useMutation({
    mutationFn: (guid: string) => cancelProgramApi(vetGuid.value, guid),
    onSuccess: (_, guid) => {
      queryClient.invalidateQueries({ queryKey: ['programs', vetGuid.value] })
      queryClient.invalidateQueries({ queryKey: ['program', vetGuid.value, guid] })
      success('Programa cancelado correctamente')
    },
    onError: (err: unknown) => {
      const raw = getRawError(err)
      if (raw.status === 422 && raw.errors && raw.errors.reason === 'not_editable') {
        error('El programa ya estaba cancelado.')
      } else {
        error('Error al cancelar el programa')
      }
    },
  })

  return { ...mutation }
}

// --- useCancelProgramWithModal ---
// Wrapper que expone cancelProgram(item) directo, para uso en la lista

export function useCancelProgramWithModal() {
  const cancelComposable = useCancelProgram()
  const selectedProgram = ref<ProgramCancelTarget | null>(null)
  const showCancelModal = ref(false)

  function openCancelModal(program: ProgramCancelTarget) {
    selectedProgram.value = program
    showCancelModal.value = true
  }

  function closeCancelModal() {
    showCancelModal.value = false
    selectedProgram.value = null
  }

  function confirmCancel() {
    if (selectedProgram.value) {
      cancelComposable.mutate(selectedProgram.value.guid, {
        onSuccess: () => {
          showCancelModal.value = false
          selectedProgram.value = null
        },
      })
    }
  }

  return {
    ...cancelComposable,
    selectedProgram,
    showCancelModal,
    openCancelModal,
    closeCancelModal,
    confirmCancel,
  }
}
