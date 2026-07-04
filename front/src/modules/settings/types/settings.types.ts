export type SettingValue = string | number | boolean

export type UserSettings = Record<string, SettingValue>

export interface UpsertSettingPayload {
    code:  string
    value: SettingValue
}
