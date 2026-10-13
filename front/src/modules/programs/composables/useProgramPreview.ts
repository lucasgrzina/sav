import { useMutation } from '@tanstack/vue-query'
import { toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { previewProgramApi } from '../api/program-alerts.api'
import type { PreviewProgramPayload } from '../types/program-alerts.types'

// Read-only mutation (POST used only for its body): it changes nothing on the server, so it
// is the documented exception to "every mutation invalidates queries".
export function useProgramPreview(vetGuid: MaybeRefOrGetter<string>) {
  return useMutation({
    mutationFn: ({ payload, guid }: { payload: PreviewProgramPayload; guid?: string | null }) =>
      previewProgramApi(toValue(vetGuid), payload, guid),
  })
}
