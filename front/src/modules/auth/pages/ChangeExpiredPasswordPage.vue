<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { changePasswordSchema } from '@/modules/users/validators/user.validator'
import { changePasswordUserApi } from '@/modules/users/api/users.api'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { useNotification } from '@/core/composables/useNotification'
import { LockOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

const authStore = useAuthStore()
const router = useRouter()
const { success, error } = useNotification()
const serverError = ref<string | null>(null)
const isSubmitting = ref(false)

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(changePasswordSchema),
})

const [password, passwordAttrs] = defineField('password')
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation')

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null
    isSubmitting.value = true
    try {
        await changePasswordUserApi(authStore.user!.guid, {
            password: values.password,
            password_confirmation: values.password_confirmation,
        })
        authStore.mustChangePassword = false
        success('Contraseña actualizada correctamente.')
        router.push('/dashboard')
    } catch (err: unknown) {
        const e = err as { message?: string }
        serverError.value = e.message ?? 'Error al cambiar la contraseña.'
        error(serverError.value)
    } finally {
        isSubmitting.value = false
    }
})
</script>

<template>
    <div>
        <h2 class="fcp-title">Actualizá tu contraseña</h2>
        <p class="fcp-subtitle">Tu contraseña expiró. Ingresá una nueva para continuar usando el sistema.</p>

        <a-alert
            v-if="serverError"
            :message="serverError"
            type="error"
            show-icon
            class="fcp-alert"
        />

        <form @submit.prevent="onSubmit" class="fcp-form">
            <div class="fcp-field">
                <label class="fcp-label">Nueva contraseña</label>
                <a-input-password
                    v-model:value="password"
                    v-bind="passwordAttrs"
                    autocomplete="new-password"
                    placeholder="Mínimo 8 caracteres"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined style="color: #9CA3AF" />
                    </template>
                </a-input-password>
                <p v-if="errors.password" class="fcp-error">{{ errors.password }}</p>
            </div>

            <div class="fcp-field">
                <label class="fcp-label">Confirmá la contraseña</label>
                <a-input-password
                    v-model:value="passwordConfirmation"
                    v-bind="passwordConfirmationAttrs"
                    autocomplete="new-password"
                    placeholder="Repetí la contraseña"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined style="color: #9CA3AF" />
                    </template>
                </a-input-password>
                <p v-if="errors.password_confirmation" class="fcp-error">{{ errors.password_confirmation }}</p>
            </div>

            <div class="fcp-hint">
                Debe tener entre 8 y 12 caracteres, incluir al menos una mayúscula, un número y un símbolo (!@#$%&).
            </div>

            <BaseButton
                variant="primary"
                html-type="submit"
                block
                size="large"
                :loading="isSubmitting"
                class="fcp-submit"
            >
                Cambiar contraseña
            </BaseButton>
        </form>
    </div>
</template>

<style scoped>
.fcp-title {
    font-size: 22px;
    font-weight: 700;
    color: #1A2433;
    margin: 0 0 8px;
    font-family: 'Figtree', system-ui, sans-serif;
    letter-spacing: -0.3px;
}

.fcp-subtitle {
    font-size: 14px;
    color: #6B7A8D;
    margin: 0 0 24px;
    line-height: 1.5;
}

.fcp-alert {
    margin-bottom: 16px;
}

.fcp-form {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.fcp-field {
    margin-bottom: 16px;
}

.fcp-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #1A2433;
    margin-bottom: 6px;
}

.fcp-error {
    font-size: 12px;
    color: #DC2626;
    margin: 4px 0 0;
}

.fcp-hint {
    font-size: 12px;
    color: #6B7A8D;
    line-height: 1.5;
    margin-bottom: 20px;
    padding: 10px 12px;
    background: rgba(240, 6, 20, 0.04);
    border: 1px solid rgba(240, 6, 20, 0.10);
    border-radius: 6px;
}

.fcp-submit {
    margin-top: 4px;
}
</style>
