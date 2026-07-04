import { defineStore } from 'pinia'
import type { SettingValue, UserSettings } from '../types/settings.types'

export const useSettingsStore = defineStore('settings', {
    state: () => ({
        all: {} as UserSettings,
    }),

    getters: {
        get: (state) => (code: string, fallback?: SettingValue): SettingValue | undefined =>
            state.all[code] ?? fallback,
    },

    actions: {
        apply(settings: UserSettings) {
            this.all = { ...settings }
        },

        set(code: string, value: SettingValue) {
            this.all[code] = value
        },
    },

    persist: true,
})
