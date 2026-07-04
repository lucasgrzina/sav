import { ref, computed, watch } from 'vue'
import { buildDashTheme, type Palette, type Mode } from '@/core/themes/palettes'
import { upsertSettingApi } from '@/modules/settings/api/settings.api'

const THEME_KEY   = 'va-theme'
const PALETTE_KEY = 'va-palette'

const themeMode = ref<Mode>(
    (localStorage.getItem(THEME_KEY) as Mode | null) ?? 'dark'
)
const palette = ref<Palette>(
    (localStorage.getItem(PALETTE_KEY) as Palette | null) ?? 'green'
)

let _isApplying = false

watch(themeMode, (t) => {
    localStorage.setItem(THEME_KEY, t)
    if (_isApplying) return
    syncToBackend('theme_mode', t)
})

watch(palette, (p) => {
    localStorage.setItem(PALETTE_KEY, p)
    if (_isApplying) return
    syncToBackend('theme_palette', p)
})

function syncToBackend(code: string, value: string): void {
    import('@/modules/auth/stores/auth.store').then(({ useAuthStore }) => {
        if (!useAuthStore().isAuthenticated) return
        upsertSettingApi({ code, value }).catch(() => {})
    }).catch(() => {})
}

export function useTheme() {
    const isDark    = computed(() => themeMode.value === 'dark')
    const isLight   = computed(() => themeMode.value === 'light')
    const dashTheme = computed(() => buildDashTheme(palette.value, themeMode.value))

    function toggle() {
        themeMode.value = themeMode.value === 'dark' ? 'light' : 'dark'
    }

    function setPalette(p: Palette) {
        palette.value = p
    }

    function applySettings(settings: Record<string, unknown>): void {
        _isApplying = true
        if (settings['theme_mode'])    themeMode.value = settings['theme_mode'] as Mode
        if (settings['theme_palette']) palette.value   = settings['theme_palette'] as Palette
        _isApplying = false
    }

    return { theme: themeMode, isDark, isLight, palette, dashTheme, toggle, setPalette, applySettings }
}
