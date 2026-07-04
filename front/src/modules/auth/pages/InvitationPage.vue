<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { resetPasswordSchema } from '@/modules/auth/validators/auth.validator';
import { useAuthStore } from '@/modules/auth/stores/auth.store';
import { useVetStore } from '@/core/stores/vet.store';
import { fetchUserVets } from '@/modules/vets/api/user-vets.api';
import { useNotification } from '@/core/composables/useNotification';
import { acceptInvitationApi } from '@/modules/auth/api/auth.api';
import { LockOutlined } from '@ant-design/icons-vue';
import AuthFormField from '@/modules/auth/components/AuthFormField.vue';
import AuthServerError from '@/modules/auth/components/AuthServerError.vue';
import BaseButton from '@/components/atoms/buttons/BaseButton.vue';
import type { User } from '@/modules/auth/types/auth.types';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const vetStore = useVetStore();
const { error: notifyError } = useNotification();

// Query params leídos al montar
const tokenParam = ref<string>('');
const emailParam = ref<string>('');

const serverError = ref<string | null>(null);
const isSubmitting = ref(false);
const isExpiredError = ref(false);

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(resetPasswordSchema),
});

const [password, passwordAttrs] = defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = defineField('password_confirmation');

onMounted(() => {
    const token = route.query.token as string | undefined;
    const email = route.query.email as string | undefined;

    if (!token || !email) {
        notifyError('El link de invitación es inválido. Pedile al administrador que reenvíe la invitación.');
        router.replace('/login');
        return;
    }

    tokenParam.value = token;
    emailParam.value = email;
});

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null;
    isExpiredError.value = false;
    isSubmitting.value = true;

    try {
        const result = await acceptInvitationApi({
            token: tokenParam.value,
            email: emailParam.value,
            password: values.password,
            password_confirmation: values.password_confirmation,
        });

        // Poblar el store (idéntico al flujo de login)
        authStore.token = result.access_token;
        authStore.user = result.user as User;
        authStore.mustVerifyAccount = false;
        authStore.mustChangePassword = false;

        // Determinar destino: buscar primera vet del usuario
        try {
            const vets = await fetchUserVets();
            if (vets.length > 0) {
                vetStore.setUserVets(vets);
                const target = vets[0].slug;
                router.push(`/vets/${target}/perfil`);
            } else {
                // Sin vets (client-owner u otro rol sin panel vet)
                notifyError('Tu cuenta fue activada. El panel para tu tipo de perfil estará disponible próximamente.');
                authStore.$reset();
                router.push('/login');
            }
        } catch {
            // fetchUserVets falló — ir al dashboard genérico
            router.push('/dashboard');
        }
    } catch (err: unknown) {
        const e = err as { message?: string; errors?: Record<string, string[]> };
        const tokenError = e.errors?.token?.[0];

        if (tokenError === 'TOKEN_EXPIRED') {
            isExpiredError.value = true;
            serverError.value = null;
        } else if (tokenError) {
            serverError.value = 'El link de invitación es inválido. Pedile al administrador que reenvíe la invitación.';
        } else {
            serverError.value = e.message ?? 'Ocurrió un error inesperado. Intentá nuevamente.';
        }
    } finally {
        isSubmitting.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="auth-form-title">Activar cuenta</h1>
        <p class="auth-form-subtitle">
            Creá tu contraseña para acceder al sistema como
            <strong style="color: var(--auth-accent)">{{ emailParam }}</strong>
        </p>

        <!-- Error de token expirado -->
        <a-alert
            v-if="isExpiredError"
            type="warning"
            show-icon
            style="margin-bottom: 20px"
        >
            <template #message>
                Tu invitación expiró. Pedile al administrador que reenvíe la invitación.
            </template>
        </a-alert>

        <!-- Otros errores de servidor -->
        <AuthServerError :message="serverError" />

        <form v-if="!isExpiredError" @submit.prevent="onSubmit">
            <AuthFormField label="Contraseña" :error="errors.password">
                <a-input-password
                    v-model:value="password"
                    v-bind="passwordAttrs"
                    autocomplete="new-password"
                    placeholder="8-12 chars, mayúscula, número y símbolo"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined />
                    </template>
                </a-input-password>
            </AuthFormField>

            <AuthFormField label="Confirmar contraseña" :error="errors.password_confirmation">
                <a-input-password
                    v-model:value="passwordConfirmation"
                    v-bind="passwordConfirmationAttrs"
                    autocomplete="new-password"
                    placeholder="Repetí tu contraseña"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined />
                    </template>
                </a-input-password>
            </AuthFormField>

            <BaseButton
                variant="primary"
                html-type="submit"
                block
                size="large"
                :loading="isSubmitting"
            >
                Activar cuenta
            </BaseButton>
        </form>

        <p class="auth-footer-text" style="margin-top: 20px">
            <RouterLink to="/login" class="auth-link">← Volver al inicio de sesión</RouterLink>
        </p>
    </div>
</template>
