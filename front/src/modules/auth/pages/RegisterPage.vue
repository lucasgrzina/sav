<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { registerSchema } from '@/modules/auth/validators/auth.validator';
import { useAuthStore } from '@/modules/auth/stores/auth.store';
import { useNotification } from '@/core/composables/useNotification';
import { MailOutlined, UserOutlined } from '@ant-design/icons-vue';
import AuthFormField from '@/modules/auth/components/AuthFormField.vue';
import AuthServerError from '@/modules/auth/components/AuthServerError.vue';
import BaseButton from '@/components/atoms/buttons/BaseButton.vue';

const router = useRouter();
const authStore = useAuthStore();
const { error: notifyError, success: notifySuccess } = useNotification();

const serverError = ref<string | null>(null);
const isSubmitting = ref(false);

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(registerSchema),
});

const [firstName, firstNameAttrs] = defineField('first_name');
const [lastName, lastNameAttrs] = defineField('last_name');
const [email, emailAttrs] = defineField('email');
const [password, passwordAttrs] = defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation');

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null;
    isSubmitting.value = true;
    try {
        const result = await authStore.register({
            first_name: values.first_name,
            last_name: values.last_name,
            email: values.email,
            password: values.password,
            password_confirmation: values.password_confirmation,
        });
        notifySuccess('Registro exitoso. Revisá tu email para verificar la cuenta.');
        router.push(`/verify-account/${result.guid}`);
    } catch (err: unknown) {
        const e = err as { message?: string };
        serverError.value = e.message ?? 'Error al registrarse.';
        notifyError(serverError.value);
    } finally {
        isSubmitting.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="auth-form-title">Crear cuenta</h1>
        <p class="auth-form-subtitle">Completá el formulario para unirte al sistema</p>

        <AuthServerError :message="serverError" />

        <form @submit.prevent="onSubmit">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                <AuthFormField label="Nombre" :error="errors.first_name">
                    <a-input
                        v-model:value="firstName"
                        v-bind="firstNameAttrs"
                        placeholder="Juan"
                        size="large"
                    >
                        <template #prefix><UserOutlined /></template>
                    </a-input>
                </AuthFormField>

                <AuthFormField label="Apellido" :error="errors.last_name">
                    <a-input
                        v-model:value="lastName"
                        v-bind="lastNameAttrs"
                        placeholder="Pérez"
                        size="large"
                    />
                </AuthFormField>
            </div>

            <AuthFormField label="Email" :error="errors.email">
                <a-input
                    v-model:value="email"
                    v-bind="emailAttrs"
                    placeholder="tu@clinica.com"
                    size="large"
                >
                    <template #prefix><MailOutlined /></template>
                </a-input>
            </AuthFormField>

            <AuthFormField label="Contraseña" :error="errors.password">
                <a-input-password
                    v-model:value="password"
                    v-bind="passwordAttrs"
                    placeholder="Mín. 8 chars, mayúscula, número y símbolo"
                    size="large"
                />
            </AuthFormField>

            <AuthFormField label="Confirmar contraseña" :error="errors.password_confirmation">
                <a-input-password
                    v-model:value="passwordConfirmation"
                    v-bind="passwordConfirmationAttrs"
                    placeholder="Repetí tu contraseña"
                    size="large"
                />
            </AuthFormField>

            <BaseButton variant="primary" html-type="submit" block size="large" :loading="isSubmitting" style="margin-top: 4px">
                Crear cuenta
            </BaseButton>
        </form>

        <p class="auth-footer-text">
            ¿Ya tenés cuenta?
            <RouterLink to="/login" class="auth-link" style="margin-left: 4px">Iniciá sesión</RouterLink>
        </p>
    </div>
</template>
