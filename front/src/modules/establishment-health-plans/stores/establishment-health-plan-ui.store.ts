import { defineStore } from 'pinia'
import { reactive } from 'vue'

interface EstablishmentHealthPlanFilters {
  client_guid?: string
  establishment_guid?: string
  cancelled?: boolean
  page: number
  per_page: number
}

export const useEstablishmentHealthPlanUiStore = defineStore('establishment-health-plan-ui', () => {
  const filters = reactive<EstablishmentHealthPlanFilters>({
    client_guid: undefined,
    establishment_guid: undefined,
    cancelled: undefined,
    page: 1,
    per_page: 15,
  })

  function reset(): void {
    filters.client_guid = undefined
    filters.establishment_guid = undefined
    filters.cancelled = undefined
    filters.page = 1
    filters.per_page = 15
  }

  return { filters, reset }
})
