import { computed } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { requestProgramPdfApi } from '../api/program-pdf.api'

// La generación del PDF de un programa siempre es asíncrona (a diferencia de
// useInitiateExport, que puede resolver sync): dispara el Export y notifica
// in-app cuando termina (ver NotifyExportCompletedListener en backend).
export function useRequestProgramPdf() {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { info, error } = useNotification()

  return useMutation({
    mutationFn: (guid: string) => requestProgramPdfApi(vetGuid.value, guid),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['exports'] })
      info('Generación de PDF iniciada. Te notificaremos cuando esté lista.')
    },
    onError: () => error('Error al iniciar la generación del PDF.'),
  })
}
