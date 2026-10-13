import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { useNotification } from '@/core/composables/useNotification'
import { ACTIONS_COPY, RETRY_REASON_COPY } from '../constants/alert-copy'
import { retryProgramAlertApi } from '../api/program-alerts.api'
import type { RetryBlockedReason } from '../types/program-alerts.types'

interface RawApiError {
  status?: number
  message?: string
  errors?: Record<string, unknown> | null
}

export function retryErrorMessage(err: unknown): string {
  const raw = err as RawApiError
  const reason = raw?.status === 422 ? raw.errors?.reason : null
  if (typeof reason === 'string' && reason in RETRY_REASON_COPY) {
    return RETRY_REASON_COPY[reason as RetryBlockedReason]
  }
  return ACTIONS_COPY.retryError
}

export function useRetryProgramAlert(
  vetGuid: MaybeRefOrGetter<string>,
  guid: MaybeRefOrGetter<string>,
) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  return useMutation({
    mutationFn: (recipientGuid: string) => retryProgramAlertApi(toValue(vetGuid), toValue(guid), recipientGuid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['program-alerts', toValue(vetGuid), toValue(guid)] })
      queryClient.invalidateQueries({ queryKey: ['program', toValue(vetGuid), toValue(guid)] })
      success(ACTIONS_COPY.retrySuccess)
    },
    onError: (err: unknown) => {
      error(retryErrorMessage(err))
      // A refused retry usually means the line changed under us: refresh the advice.
      queryClient.invalidateQueries({ queryKey: ['program-alerts', toValue(vetGuid), toValue(guid)] })
    },
  })
}
