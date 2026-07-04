import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { lookupVetStaffApi } from '@/modules/vets/api/vet-staff.api'

export function useLookupVetStaff() {
  const route       = useRoute()
  const vetGuid     = computed(() => route.params.vetGuid as string)
  const email       = ref<string>('')
  const enabled     = ref(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['vet-staff-lookup', vetGuid, email],
    queryFn:  () => lookupVetStaffApi(vetGuid.value, email.value),
    enabled:  computed(() => enabled.value && Boolean(email.value)),
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
    queryClient.removeQueries({ queryKey: ['vet-staff-lookup', vetGuid.value] })
  }

  return { ...query, email, search, reset }
}
