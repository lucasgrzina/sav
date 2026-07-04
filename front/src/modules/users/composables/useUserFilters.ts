import { reactive, toRef, watch, type Ref } from 'vue'
import { useDebounce } from '@/core/composables/useDebounce'
import { useTablePageSize } from '@/modules/settings/composables/useTablePageSize'
import type { UserFilters } from '../types/user.types'

export function useUserFilters() {
    const { perPage: storedPerPage, setPerPage } = useTablePageSize('users', 15)

    const filters = reactive<UserFilters>({
        search: '',
        status: null,
        date_from: '',
        date_to: '',
        page: 1,
        per_page: storedPerPage.value,
    })

    const searchRef = toRef(filters, 'search')
    const debouncedSearch = useDebounce(searchRef as Ref<string>, 400)

    watch(() => filters.per_page, (size: number | undefined ) => {
        setPerPage(size)
    })

    function reset() {
        filters.search = ''
        filters.status = null
        filters.date_from = ''
        filters.date_to = ''
        filters.page = 1
        filters.per_page = storedPerPage.value
    }

    return { filters, debouncedSearch, reset }
}
