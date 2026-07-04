import { reactive, toRef, watch, type Ref } from 'vue'
import { useDebounce } from '@/core/composables/useDebounce'
import { useTablePageSize } from '@/modules/settings/composables/useTablePageSize'
import type { RoleFilters } from '../types/role.types'

export function useRoleFilters() {
  const { perPage: storedPerPage, setPerPage } = useTablePageSize('roles', 15)

  const filters = reactive<RoleFilters>({
    search: '',
    page: 1,
    per_page: storedPerPage.value,
  })

  const searchRef = toRef(filters, 'search')
  const debouncedSearch = useDebounce(searchRef as Ref<string>, 400)

  watch(() => filters.per_page, (size) => {
    setPerPage(size)
  })

  function reset() {
    filters.search = ''
    filters.page = 1
    filters.per_page = storedPerPage.value
  }

  return { filters, debouncedSearch, reset }
}
