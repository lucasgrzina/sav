import { http } from '@/core/api/http'
import type { UserSettings, UpsertSettingPayload } from '../types/settings.types'

export async function getUserSettingsApi(): Promise<UserSettings> {
    const response = await http.get<UserSettings>('/v1/user/settings')
    return response.data
}

export async function upsertSettingApi(payload: UpsertSettingPayload): Promise<UserSettings> {
    const response = await http.patch<UserSettings>('/v1/user/settings', payload)
    return response.data
}
