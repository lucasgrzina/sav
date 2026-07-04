import { ref, shallowRef } from 'vue'
import { useSettingsStore } from '../stores/settings.store'
import { upsertSettingApi } from '../api/settings.api'
import { useTheme } from '@/core/composables/useTheme'
import type { SettingDefinition } from '../config/settings.registry'
import type { SettingValue } from '../types/settings.types'

export function useSettingEditor() {
    const store = useSettingsStore()
    const { applySettings } = useTheme()

    const isOpen    = shallowRef(false)
    const isSaving  = shallowRef(false)
    const active    = ref<SettingDefinition | null>(null)
    const draftValue = ref<SettingValue>('')

    function open(definition: SettingDefinition) {
        active.value     = definition
        draftValue.value = store.get(definition.code, definition.options[0]?.value as SettingValue) as SettingValue
        isOpen.value     = true
    }

    function close() {
        isOpen.value = false
        active.value = null
    }

    async function save() {
        if (!active.value) return
        isSaving.value = true
        const { code } = active.value
        const value    = draftValue.value
        try {
            store.set(code, value)
            applySettings({ [code]: value })
            await upsertSettingApi({ code, value })
        } finally {
            isSaving.value = false
            close()
        }
    }

    return { isOpen, isSaving, active, draftValue, open, close, save }
}
