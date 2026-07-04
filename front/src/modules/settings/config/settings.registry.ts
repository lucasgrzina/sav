import type { Palette } from '@/core/themes/palettes'

export interface SettingOption {
    label: string
    value: string | number
    color?: string
}

export interface SettingDefinition {
    code: string
    label: string
    group: string
    editor: 'select'
    options: SettingOption[]
    format: (value: string | number | boolean) => string
}

export const SETTINGS_REGISTRY: SettingDefinition[] = [
    {
        code: 'theme_mode',
        label: 'Modo de tema',
        group: 'Apariencia',
        editor: 'select',
        options: [
            { label: 'Oscuro', value: 'dark' },
            { label: 'Claro',  value: 'light' },
        ],
        format: (v) => v === 'dark' ? 'Oscuro' : 'Claro',
    },
    {
        code: 'theme_palette',
        label: 'Paleta de color',
        group: 'Apariencia',
        editor: 'select',
        options: [
            { label: 'Verde',    value: 'green',  color: '#1AE5A0' },
            { label: 'Azul',     value: 'blue',   color: '#38BDF8' },
            { label: 'Rojo',     value: 'red',    color: '#FF5A6A' },
            { label: 'Amarillo', value: 'yellow', color: '#FBBF24' },
        ] as { label: string; value: Palette; color: string }[],
        format: (v) => {
            const map: Record<string, string> = { green: 'Verde', blue: 'Azul', red: 'Rojo', yellow: 'Amarillo' }
            return map[v as string] ?? String(v)
        },
    },
    {
        code: 'table_page_size.users',
        label: 'Registros por página — Usuarios',
        group: 'Tablas',
        editor: 'select',
        options: [10, 25, 50, 100].map((n) => ({ label: `${n} por página`, value: n })),
        format: (v) => `${v} por página`,
    },
    {
        code: 'table_page_size.roles',
        label: 'Registros por página — Roles',
        group: 'Tablas',
        editor: 'select',
        options: [10, 25, 50, 100].map((n) => ({ label: `${n} por página`, value: n })),
        format: (v) => `${v} por página`,
    }
]

export const REGISTRY_BY_CODE = Object.fromEntries(
    SETTINGS_REGISTRY.map((s) => [s.code, s]),
) as Record<string, SettingDefinition>

export const REGISTRY_GROUPS = SETTINGS_REGISTRY.reduce<Record<string, SettingDefinition[]>>(
    (acc, def) => {
        ;(acc[def.group] ??= []).push(def)
        return acc
    },
    {},
)
