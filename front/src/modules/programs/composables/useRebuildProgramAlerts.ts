import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { useNotification } from '@/core/composables/useNotification'
import { ACTIONS_COPY } from '../constants/alert-copy'
import { rebuildProgramAlertsApi } from '../api/program-alerts.api'

export function useRebuildProgramAlerts(
  vetGuid: MaybeRefOrGetter<string>,
  guid: MaybeRefOrGetter<string>,
) {
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  return useMutation({
    mutationFn: () => rebuildProgramAlertsApi(toValue(vetGuid), toValue(guid)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['program-alerts', toValue(vetGuid), toValue(guid)] })
      queryClient.invalidateQueries({ queryKey: ['program', toValue(vetGuid), toValue(guid)] })
      success(ACTIONS_COPY.rebuildSuccess)
    },
    onError: (err: unknown) => {
      const raw = err as { status?: number; errors?: Record<string, unknown> | null; message?: string }
      const notEditable = raw?.status === 422 && raw.errors?.reason === 'not_editable'
      error(notEditable && raw.message ? raw.message : ACTIONS_COPY.rebuildError)
    },
  })
}
