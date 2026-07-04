import { http } from '@/core/api/http'
import type { SystemSetting, UpdateSystemSettingPayload } from '../types/system-settings.types'

export async function listSystemSettingsApi(): Promise<SystemSetting[]> {
    const response = await http.get<SystemSetting[]>('/v1/system-settings')
    return response.data
}

export async function getSystemSettingApi(code: string): Promise<SystemSetting> {
    const response = await http.get<SystemSetting>(`/v1/system-settings/${code}`)
    return response.data
}

export async function updateSystemSettingApi(
    code: string,
    payload: UpdateSystemSettingPayload,
): Promise<SystemSetting> {
    const response = await http.patch<SystemSetting>(`/v1/system-settings/${code}`, payload)
    return response.data
}
