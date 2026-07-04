import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { updateSystemSettingApi } from '../api/system-settings.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import { ref } from 'vue'
import type { UpdateSystemSettingPayload } from '../types/system-settings.types'

export function useUpdateSystemSetting() {
    const queryClient = useQueryClient()
    const { success, error } = useNotification()
    const fieldErrors = ref<Record<string, string> | null>(null)
    const generalError = ref<string | null>(null)

    const mutation = useMutation({
        mutationFn: ({ code, payload }: { code: string; payload: UpdateSystemSettingPayload }) =>
            updateSystemSettingApi(code, payload),
        onMutate: () => {
            fieldErrors.value = null
            generalError.value = null
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['system-settings'] })
            success('Configuración actualizada correctamente.')
        },
        onError: (err: unknown) => {
            const apiError = parseApiError(err)
            fieldErrors.value = apiError.fieldErrors
            generalError.value = apiError.message ?? 'Error al actualizar la configuración.'
            if (apiError.message) error('Error al actualizar la configuración.')
        },
    })

    return { ...mutation, fieldErrors, generalError }
}
