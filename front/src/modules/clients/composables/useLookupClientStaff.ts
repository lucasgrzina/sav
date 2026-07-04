import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { lookupClientStaffApi } from '../api/client-staff.api'

export function useLookupClientStaff() {
  const route      = useRoute()
  const vetGuid    = computed(() => route.params.vetGuid as string)
  const clientGuid = computed(() => route.params.clientGuid as string)
  const email      = ref<string>('')
  const enabled    = ref(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['client-staff-lookup', vetGuid, clientGuid, email],
    queryFn:  () => lookupClientStaffApi(vetGuid.value, clientGuid.value, email.value),
    enabled:  computed(() => enabled.value && Boolean(email.value) && Boolean(vetGuid.value) && Boolean(clientGuid.value)),
    staleTime: 0,
    retry: false,
  })

  function search(newEmail: string): void {
    email.value   = newEmail
    enabled.value = true
  }

  function reset(): void {
    email.value   = ''
    enabled.value = false
    queryClient.removeQueries({ queryKey: ['client-staff-lookup', vetGuid.value, clientGuid.value] })
  }

  return { ...query, email, search, reset }
}
