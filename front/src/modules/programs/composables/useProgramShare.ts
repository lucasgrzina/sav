import { computed, type Ref } from 'vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { getShareRecipientsApi, shareProgramPdfApi } from '../api/program-pdf.api'

// `isOpen` gobierna tanto el enabled de la query (no pedimos destinatarios con
// el modal cerrado) como el cierre automático del modal al enviar con éxito.
export function useProgramShare(programGuid: Ref<string | null>, isOpen: Ref<boolean>) {
  const route = useRoute()
  const vetGuid = computed(() => route.params.vetGuid as string)
  const queryClient = useQueryClient()
  const { success, error } = useNotification()

  const recipientsQueryKey = computed(() => [
    'program-share-recipients',
    vetGuid.value,
    programGuid.value,
  ])

  const recipientsQuery = useQuery({
    queryKey: recipientsQueryKey,
    queryFn: () => getShareRecipientsApi(vetGuid.value, programGuid.value as string),
    enabled: computed(() => isOpen.value && !!programGuid.value),
  })

  const shareMutation = useMutation({
    mutationFn: (managerProfileIds: string[]) =>
      shareProgramPdfApi(vetGuid.value, programGuid.value as string, {
        manager_profile_ids: managerProfileIds,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipientsQueryKey.value })
      success('Envío iniciado.')
      isOpen.value = false
    },
    onError: () => error('Error al enviar el programa por WhatsApp.'),
  })

  return {
    recipients: computed(() => recipientsQuery.data.value ?? []),
    isLoadingRecipients: recipientsQuery.isLoading,
    share: shareMutation.mutate,
    isSharing: shareMutation.isPending,
  }
}
