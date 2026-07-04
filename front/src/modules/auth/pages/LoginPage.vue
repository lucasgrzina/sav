<script setup lang="ts">
import { shallowRef } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { loginSchema } from '@/modules/auth/validators/auth.validator';
import { useAuthStore } from '@/modules/auth/stores/auth.store';
import { useVetStore } from '@/core/stores/vet.store';
import { fetchUserVets } from '@/modules/vets/api/user-vets.api';
import { useNotification } from '@/core/composables/useNotification';
import { MailOutlined, LockOutlined } from '@ant-design/icons-vue';
import AuthFormField from '@/modules/auth/components/AuthFormField.vue';
import AuthServerError from '@/modules/auth/components/AuthServerError.vue';
import BaseButton from '@/components/atoms/buttons/BaseButton.vue';

const router = useRouter();
const authStore = useAuthStore();
const vetStore = useVetStore();
const { error: notifyError } = useNotification();

async function resolvePostLoginRoute(): Promise<string> {
    // Usuarios con roles Spatie son admins — van al dashboard
    if (authStore.user?.roles && authStore.user.roles.length > 0) {
        return '/dashboard';
    }

    // Sin roles admin: verificar si tiene vets activas
    try {
        const vets = await fetchUserVets();
        if (vets.length === 0) return '/dashboard';

        vetStore.setUserVets(vets);

        // Preferir el último guid visitado si sigue siendo válido
        const lastGuid = vetStore.lastVisitedGuid;
        const target = (lastGuid && vets.find(v => v.guid === lastGuid))
            ? lastGuid
            : vets[0].guid;

        return `/vets/${target}/perfil`;
    } catch {
        return '/dashboard';
    }
}

const serverError = shallowRef<string | null>(null);
const isSubmitting = shallowRef(false);

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(loginSchema),
});

const [email, emailAttrs] = defineField('email');
const [password, passwordAttrs] = defineField('password');
const [remember, rememberAttrs] = defineField('remember');

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null;
    isSubmitting.value = true;
    try {
        const result = await authStore.login(values.email, values.password, values.remember ?? false);
        if (result.must_verify_account && result.user) {
            router.push(`/verify-account/${result.user.guid}`);
            return;
        }
        if (result.must_change_password) {
            router.push('/change-expired-password');
            return;
        }
        const destination = await resolvePostLoginRoute();
        router.push(destination);
    } catch (err: unknown) {
        const e = err as { message?: string };
        serverError.value = e.message ?? 'Error al iniciar sesión.';
        notifyError(serverError.value);
    } finally {
        isSubmitting.value = false;
    }
});
</script>

<template>
    <div>
        <h1 class="auth-form-title">Bienvenido</h1>
        <p class="auth-form-subtitle">Ingresá tus credenciales para acceder al sistema</p>

        <AuthServerError :message="serverError" />

        <form @submit.prevent="onSubmit">
            <AuthFormField label="Email" :error="errors.email">
                <a-input
                    v-model:value="email"
                    v-bind="emailAttrs"
                    autocomplete="email"
                    placeholder="tu@clinica.com"
                    size="large"
                >
                    <template #prefix>
                        <MailOutlined />
                    </template>
                </a-input>
            </AuthFormField>

            <AuthFormField label="Contraseña" :error="errors.password">
                <a-input-password
                    v-model:value="password"
                    v-bind="passwordAttrs"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    size="large"
                >
                    <template #prefix>
                        <LockOutlined />
                    </template>
                </a-input-password>
            </AuthFormField>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px">
                <a-checkbox v-model:checked="remember" v-bind="rememberAttrs">
                    Recordarme
                </a-checkbox>
                <RouterLink to="/forgot-password" class="auth-link">
                    ¿Olvidaste tu contraseña?
                </RouterLink>
            </div>

            <BaseButton variant="primary" html-type="submit" block size="large" :loading="isSubmitting">
                Iniciar sesión
            </BaseButton>
        </form>

        <p class="auth-footer-text">
            ¿No tenés cuenta?
            <RouterLink to="/register" class="auth-link" style="margin-left: 4px">Crear cuenta</RouterLink>
        </p>
    </div>
</template>
