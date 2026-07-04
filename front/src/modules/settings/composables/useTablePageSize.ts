import { computed } from 'vue'
import { useSettingsStore } from '../stores/settings.store'
import { upsertSettingApi } from '../api/settings.api'

export function useTablePageSize(tableKey: string, defaultSize = 15) {
    const store = useSettingsStore()
    const code  = `table_page_size.${tableKey}`

    const perPage = computed(() => (store.get(code, defaultSize) as number))

    function setPerPage(size: number): void {
        store.set(code, size)
        upsertSettingApi({ code, value: size }).catch(() => {
            // Fallo silencioso
        })
    }

    return { perPage, setPerPage }
}
