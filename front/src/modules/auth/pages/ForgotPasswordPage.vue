<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { forgotPasswordEmailSchema, verifyCodeSchema, resetPasswordSchema } from '@/modules/auth/validators/auth.validator';
import { useNotification } from '@/core/composables/useNotification';
import { MailOutlined, LockOutlined } from '@ant-design/icons-vue';
import AuthFormField from '@/modules/auth/components/AuthFormField.vue';
import AuthServerError from '@/modules/auth/components/AuthServerError.vue';
import BaseButton from '@/components/atoms/buttons/BaseButton.vue';
import { forgotPasswordVerifyEmailApi, forgotPasswordVerifyCodeApi, forgotPasswordResendCodeApi, forgotPasswordResetPasswordApi } from '@/modules/auth/api/auth.api';

const router = useRouter();
const { error: notifyError, success: notifySuccess } = useNotification();

const step = ref<1 | 2 | 3>(1);
const token = ref('');
const guid = ref('');
const isSubmitting = ref(false);
const serverError = ref<string | null>(null);

const formStep1 = useForm({ validationSchema: toTypedSchema(forgotPasswordEmailSchema) });
const [email, emailAttrs] = formStep1.defineField('email');

const formStep2 = useForm({ validationSchema: toTypedSchema(verifyCodeSchema) });
const [code, codeAttrs] = formStep2.defineField('code');

const formStep3 = useForm({ validationSchema: toTypedSchema(resetPasswordSchema) });
const [password, passwordAttrs] = formStep3.defineField('password');
const [passwordConfirmation, passwordConfirmationAttrs] = formStep3.defineField('password_confirmation');

const submitStep1 = formStep1.handleSubmit(async (values) => {
    serverError.value = null; isSubmitting.value = true;
    try {
        const result = await forgotPasswordVerifyEmailApi({ email: values.email });
        token.value = result.token; guid.value = result.guid; step.value = 2;
    } catch (err: unknown) {
        const e = err as { message?: string };
        serverError.value = e.message ?? 'Error al enviar el código.';
        notifyError(serverError.value);
    } finally { isSubmitting.value = false; }
});

const submitStep2 = formStep2.handleSubmit(async (values) => {
    serverError.value = null; isSubmitting.value = true;
    try {
        await forgotPasswordVerifyCodeApi({ token: token.value, code: values.code });
        step.value = 3;
    } catch (err: unknown) {
        const e = err as { message?: string };
        serverError.value = e.message ?? 'Código inválido.';
        notifyError(serverError.value);
    } finally { isSubmitting.value = false; }
});

const submitStep3 = formStep3.handleSubmit(async (values) => {
    serverError.value = null; isSubmitting.value = true;
    try {
        await forgotPasswordResetPasswordApi({ guid: guid.value, password: values.password, password_confirmation: values.password_confirmation });
        notifySuccess('Contraseña actualizada. Ya podés iniciar sesión.');
        router.push('/login');
    } catch (err: unknown) {
        const e = err as { message?: string };
        serverError.value = e.message ?? 'Error al actualizar la contraseña.';
        notifyError(serverError.value);
    } finally { isSubmitting.value = false; }
});

async function resendCode() {
    isSubmitting.value = true;
    try {
        const result = await forgotPasswordResendCodeApi({ guid: guid.value });
        token.value = result.token;
        notifySuccess('Código reenviado. Revisá tu email.');
    } catch (err: unknown) {
        const e = err as { message?: string };
        notifyError(e.message ?? 'Error al reenviar el código.');
    } finally { isSubmitting.value = false; }
}
</script>

<template>
    <div>
        <!-- Step indicator -->
        <div class="step-bar">
            <div
                v-for="n in 3"
                :key="n"
                class="step-dot"
                :class="{ active: step === n, done: step > n }"
            >
                <template v-if="step > n">
                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="1.5,5 4,7.5 8.5,2.5"/>
                    </svg>
                </template>
                <template v-else>{{ n }}</template>
            </div>
            <div class="step-line" :style="{ width: `${(step - 1) * 50}%` }" />
        </div>

        <AuthServerError :message="serverError" />

        <!-- Paso 1: Email -->
        <transition name="slide" mode="out-in">
            <div v-if="step === 1" key="s1">
                <h1 class="auth-form-title">Recuperar contraseña</h1>
                <p class="auth-form-subtitle">Ingresá tu email y te enviamos un código de verificación</p>
                <form @submit.prevent="submitStep1">
                    <AuthFormField label="Email" :error="formStep1.errors.value.email">
                        <a-input
                            v-model:value="email"
                            v-bind="emailAttrs"
                            placeholder="tu@clinica.com"
                            size="large"
                        >
                            <template #prefix><MailOutlined /></template>
                        </a-input>
                    </AuthFormField>
                    <BaseButton variant="primary" html-type="submit" block size="large" :loading="isSubmitting">
                        Enviar código
                    </BaseButton>
                </form>
            </div>

            <!-- Paso 2: Código -->
            <div v-else-if="step === 2" key="s2">
                <h1 class="auth-form-title">Verificá el código</h1>
                <p class="auth-form-subtitle">Revisá tu email e ingresá el código de 6 dígitos</p>
                <form @submit.prevent="submitStep2">
                    <div style="margin-bottom: 18px">
                        <label class="auth-field-label" style="text-align:center; display:block">Código</label>
                        <a-input
                            v-model:value="code"
                            v-bind="codeAttrs"
                            class="auth-code-input"
                            size="large"
                            maxlength="6"
                            inputmode="numeric"
                            placeholder="000000"
                        />
                        <span v-if="formStep2.errors.value.code" class="auth-field-error" style="justify-content:center">
                            {{ formStep2.errors.value.code }}
                        </span>
                    </div>
                    <BaseButton variant="primary" html-type="submit" block size="large" :loading="isSubmitting">
                        Verificar código
                    </BaseButton>
                    <div style="text-align: center; margin-top: 12px">
                        <BaseButton variant="tertiary" @click="resendCode">Reenviar código</BaseButton>
                    </div>
                </form>
            </div>

            <!-- Paso 3: Nueva contraseña -->
            <div v-else key="s3">
                <h1 class="auth-form-title">Nueva contraseña</h1>
                <p class="auth-form-subtitle">Elegí una contraseña segura para tu cuenta</p>
                <form @submit.prevent="submitStep3">
                    <AuthFormField label="Nueva contraseña" :error="formStep3.errors.value.password">
                        <a-input-password
                            v-model:value="password"
                            v-bind="passwordAttrs"
                            placeholder="Mín. 8 chars, mayúscula, número y símbolo"
                            size="large"
                        >
                            <template #prefix><LockOutlined /></template>
                        </a-input-password>
                    </AuthFormField>
                    <AuthFormField label="Confirmar contraseña" :error="formStep3.errors.value.password_confirmation">
                        <a-input-password
                            v-model:value="passwordConfirmation"
                            v-bind="passwordConfirmationAttrs"
                            placeholder="Repetí tu contraseña"
                            size="large"
                        >
                            <template #prefix><LockOutlined /></template>
                        </a-input-password>
                    </AuthFormField>
                    <BaseButton variant="primary" html-type="submit" block size="large" :loading="isSubmitting">
                        Actualizar contraseña
                    </BaseButton>
                </form>
            </div>
        </transition>

        <p class="auth-footer-text" style="margin-top: 20px">
            <RouterLink to="/login" class="auth-link">← Volver al inicio de sesión</RouterLink>
        </p>
    </div>
</template>

<style scoped>
.step-bar {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 32px;
}

.step-dot {
    position: relative;
    z-index: 1;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
    background: rgba(240, 6, 20, 0.06);
    border: 1.5px solid rgba(240, 6, 20, 0.22);
    color: var(--auth-muted);
    transition: all 0.3s ease;
    margin: 0 32px;
}

.step-dot.active {
    background: var(--auth-accent);
    border-color: var(--auth-accent);
    color: #FFFFFF;
    box-shadow: 0 0 16px rgba(240, 6, 20, 0.30);
}

.step-dot.done {
    background: rgba(240, 6, 20, 0.10);
    border-color: var(--auth-accent);
    color: var(--auth-accent);
}

.step-line {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    height: 1.5px;
    background: var(--auth-accent);
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    max-width: 130px;
}

.slide-enter-active,
.slide-leave-active {
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}
.slide-enter-from { opacity: 0; transform: translateX(20px); }
.slide-leave-to   { opacity: 0; transform: translateX(-20px); }
</style>
