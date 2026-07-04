import { ref, shallowRef } from 'vue'

export type ExportFormat = 'pdf' | 'xlsx' | 'txt' | 'csv'

export interface ExportFormatOption {
    value: ExportFormat
    label: string
}

export const EXPORT_FORMAT_OPTIONS: ExportFormatOption[] = [
    { value: 'pdf',  label: 'PDF' },
    { value: 'xlsx', label: 'Excel (.xlsx)' },
    { value: 'csv',  label: 'CSV (.csv)' },
    { value: 'txt',  label: 'Texto (.txt)' },
]

// Module-level singleton — shared across all usages
const visible = shallowRef(false)
const loading = shallowRef(false)
const format  = shallowRef<ExportFormat | null>(null)
let resolvePromise: ((v: ExportFormat | null) => void) | null = null

export function useExportFormat() {
    function open(): Promise<ExportFormat | null> {
        format.value  = null
        loading.value = false
        visible.value = true
        return new Promise(resolve => {
            resolvePromise = resolve
        })
    }

    function confirm() {
        visible.value = false
        resolvePromise?.(format.value)
        resolvePromise = null
    }

    function cancel() {
        visible.value = false
        resolvePromise?.(null)
        resolvePromise = null
    }

    return {
        visible,
        loading,
        format,
        options: EXPORT_FORMAT_OPTIONS,
        open,
        confirm,
        cancel,
    }
}
