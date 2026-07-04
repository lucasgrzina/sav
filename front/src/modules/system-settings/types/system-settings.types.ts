export type SettingType = 'string' | 'integer' | 'boolean' | 'json'

export interface SystemSetting {
    code: string
    value: string | number | boolean
    type: SettingType
    description: string | null
    updated_at: string | null
}

export interface UpdateSystemSettingPayload {
    value: string
}
