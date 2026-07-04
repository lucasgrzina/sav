import { defineStore } from 'pinia'
import { reactive, toRef, watch, type Ref } from 'vue'
import { useDebounce } from '@/core/composables/useDebounce'
import { useTablePageSize } from '@/modules/settings/composables/useTablePageSize'
import type { VetFilters } from '../types/vet.types'

export const useVetsUiStore = defineStore('vets-ui', () => {
  const { perPage: storedPerPage, setPerPage } = useTablePageSize('vets', 15)

  const filters = reactive<VetFilters>({
    search: '',
    validated: '',
    suspended: '',
    page: 1,
    per_page: storedPerPage.value,
  })

  const searchRef = toRef(filters, 'search')
  const debouncedSearch = useDebounce(searchRef as Ref<string>, 400)

  watch(() => filters.per_page, (size) => {
    if (size !== undefined) setPerPage(size)
  })

  function reset() {
    filters.search = ''
    filters.validated = ''
    filters.suspended = ''
    filters.page = 1
    filters.per_page = storedPerPage.value
  }

  return { filters, debouncedSearch, reset }
})
