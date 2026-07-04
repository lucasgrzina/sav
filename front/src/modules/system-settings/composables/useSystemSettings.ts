import { useQuery } from '@tanstack/vue-query'
import { listSystemSettingsApi } from '../api/system-settings.api'

export function useSystemSettings() {
    return useQuery({
        queryKey: ['system-settings'],
        queryFn: listSystemSettingsApi,
    })
}
