import { defineStore } from 'pinia'
import { reactive, toRef, watch, type Ref } from 'vue'
import { useDebounce } from '@/core/composables/useDebounce'
import { useTablePageSize } from '@/modules/settings/composables/useTablePageSize'
import type { ClientFilters } from '../types/client.types'

export const useClientsUiStore = defineStore('clients-ui', () => {
  const { perPage: storedPerPage, setPerPage } = useTablePageSize('clients', 15)

  const filters = reactive<ClientFilters>({
    search: '',
    page: 1,
    per_page: storedPerPage.value,
  })

  const searchRef = toRef(filters, 'search')
  const debouncedSearch = useDebounce(searchRef as Ref<string>, 400)

  watch(() => filters.per_page, (size) => {
    if (size !== undefined) setPerPage(size)
  })

  function reset(): void {
    filters.search   = ''
    filters.page     = 1
    filters.per_page = storedPerPage.value
  }

  return { filters, debouncedSearch, reset }
})
