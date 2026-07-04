import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { initiateExportApi, downloadExportApi } from '../api/exports.api'
import { useNotification } from '@/core/composables/useNotification'
import type { InitiateExportPayload, ExportItem } from '../types/export.types'

export function useInitiateExport() {
  const queryClient = useQueryClient()
  const { success, error, info } = useNotification()

  return useMutation({
    mutationFn: (payload: InitiateExportPayload) => initiateExportApi(payload),
    onSuccess: async (exportItem: ExportItem) => {
      queryClient.invalidateQueries({ queryKey: ['exports'] })

      if (exportItem.status === 'completed' && exportItem.is_downloadable) {
        // Síncrono: descargar inmediatamente
        await downloadExportApi(exportItem.guid, exportItem.file_name!)
        success('Exportación completada. Descargando...')
      } else {
        // Asíncrono: notificar que se está procesando
        info('Exportación en proceso. Te notificaremos cuando esté lista.')
      }
    },
    onError: () => error('Error al iniciar la exportación.'),
  })
}
