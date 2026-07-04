<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { resetPasswordSchema } from '@/modules/auth/validators/auth.validator';
import { useNotification } from '@/core/composables/useNotification';
import { LockOutlined } from '@ant-design/icons-vue';
import BaseButton from '@/components/atoms/buttons/BaseButton.vue';
import { forgotPasswordResetPasswordApi } from '@/modules/auth/api/auth.api';

const props = defineProps<{ guid: string }>();
const router = useRouter();
const { error: notifyError, success: notifySuccess } = useNotification();

const serverError = ref<string | null>(null);
const serverErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

const { handleSubmit, errors, defineField, setErrors } = useForm({
    validationSchema: toTypedSchema(resetPasswordSchema),
});

const [password, passwordAttrs] = defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation');

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null;
    serverErrors.value = {};
    isSubmitting.value = true;

    try {
        await forgotPasswordResetPasswordApi({ guid: props.guid, password: values.password, password_confirmation: values.password_confirmation });
        notifySuccess('Contraseña actualizada correctamente. Ya podés iniciar sesión.');
        router.push('/login');
    } catch (err: unknown) {
        const e = err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } }; message?: string };
        if (e?.response?.status === 422 && e.response.data?.errors) {
            const apiErrors = e.response.data.errors;
            const fieldMap: Record<string, string> = {};
            Object.keys(apiErrors).forEach((key) => {
                fieldMap[key] = apiErrors[key][0];
            });
            setErrors(fieldMap);
            serverErrors.value = fieldMap;
        } else {
            serverError.value = e.message ?? 'Error al actualizar la contraseña.';
            notifyError(serverError.value);
        }
    } finally {
        isSubmitting.value = false;
    }
});
</script>

<template>
    <div>
        <a-typography-title :level="3" style="margin-bottom: 24px">Nueva contraseña</a-typography-title>

        <a-alert
            v-if="serverError"
            :message="serverError"
            type="error"
            show-icon
            style="margin-bottom: 16px"
        />

        <a-form layout="vertical" @submit.prevent="onSubmit">
            <a-form-item
                label="Nueva contraseña"
                :validate-status="errors.password || serverErrors.password ? 'error' : ''"
                :help="errors.password || serverErrors.password || ''"
            >
                <a-input-password
                    v-model:value="password"
                    v-bind="passwordAttrs"
                    placeholder="8-12 chars, mayúscula, número y símbolo"
                >
                    <template #prefix>
                        <LockOutlined style="color: rgba(0,0,0,0.25)" />
                    </template>
                </a-input-password>
            </a-form-item>

            <a-form-item
                label="Confirmar contraseña"
                :validate-status="errors.password_confirmation || serverErrors.password_confirmation ? 'error' : ''"
                :help="errors.password_confirmation || serverErrors.password_confirmation || ''"
            >
                <a-input-password
                    v-model:value="passwordConfirmation"
                    v-bind="passwordConfirmationAttrs"
                    placeholder="Repetí tu contraseña"
                >
                    <template #prefix>
                        <LockOutlined style="color: rgba(0,0,0,0.25)" />
                    </template>
                </a-input-password>
            </a-form-item>

            <a-form-item style="margin-bottom: 8px">
                <BaseButton variant="primary" html-type="submit" block :loading="isSubmitting">
                    Cambiar contraseña
                </BaseButton>
            </a-form-item>
        </a-form>

        <p style="text-align: center; margin-top: 16px">
            <RouterLink to="/login" class="auth-link">Volver al login</RouterLink>
        </p>
    </div>
</template>
