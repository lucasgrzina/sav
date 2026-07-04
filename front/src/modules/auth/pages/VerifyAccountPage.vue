<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import { verifyCodeSchema } from '@/modules/auth/validators/auth.validator';
import { useNotification } from '@/core/composables/useNotification';
import AuthServerError from '@/modules/auth/components/AuthServerError.vue';
import BaseButton from '@/components/atoms/buttons/BaseButton.vue';
import { verifyCodeApi, resendVerificationCodeApi } from '@/modules/auth/api/auth.api';

const props = defineProps<{ guid: string }>();
const router = useRouter();
const { error: notifyError, success: notifySuccess } = useNotification();

const serverError = ref<string | null>(null);
const isSubmitting = ref(false);
const isResending = ref(false);
const cooldown = ref(0);
let cooldownInterval: ReturnType<typeof setInterval> | null = null;

const { handleSubmit, errors, defineField } = useForm({
    validationSchema: toTypedSchema(verifyCodeSchema),
});

const [code, codeAttrs] = defineField('code');

function startCooldown(seconds = 120) {
    cooldown.value = seconds;
    cooldownInterval = setInterval(() => {
        cooldown.value--;
        if (cooldown.value <= 0) { clearInterval(cooldownInterval!); cooldownInterval = null; }
    }, 1000);
}

onUnmounted(() => { if (cooldownInterval) clearInterval(cooldownInterval); });

const onSubmit = handleSubmit(async (values) => {
    serverError.value = null;
    isSubmitting.value = true;
    try {
        await verifyCodeApi({ guid: props.guid, code: values.code });
        notifySuccess('Email verificado correctamente. Ya podés iniciar sesión.');
        router.push('/login');
    } catch (err: unknown) {
        const e = err as { message?: string };
        serverError.value = e.message ?? 'Error al verificar el código.';
        notifyError(serverError.value);
    } finally {
        isSubmitting.value = false;
    }
});

async function resendCode() {
    if (cooldown.value > 0 || isResending.value) return;
    isResending.value = true;
    try {
        await resendVerificationCodeApi({ guid: props.guid });
        notifySuccess('Código reenviado. Revisá tu email.');
        startCooldown(120);
    } catch (err: unknown) {
        const e = err as { message?: string };
        notifyError(e.message ?? 'Error al reenviar el código.');
    } finally {
        isResending.value = false;
    }
}
</script>

<template>
    <div>
        <!-- Icon -->
        <div class="verify-icon">
            <div class="verify-icon-ring" />
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#f00614" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
        </div>

        <h1 class="auth-form-title" style="text-align: center">Verificá tu cuenta</h1>
        <p class="auth-form-subtitle" style="text-align: center">
            Ingresá el código de 6 dígitos que enviamos a tu email
        </p>

        <AuthServerError :message="serverError" />

        <form @submit.prevent="onSubmit">
            <div style="margin-bottom: 18px">
                <label class="auth-field-label" style="text-align: center; display: block">Código de verificación</label>
                <a-input
                    v-model:value="code"
                    v-bind="codeAttrs"
                    class="auth-code-input"
                    size="large"
                    maxlength="6"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    placeholder="000000"
                />
                <transition name="err">
                    <span v-if="errors.code" class="auth-field-error" style="justify-content: center">
                        {{ errors.code }}
                    </span>
                </transition>
            </div>

            <BaseButton variant="primary" html-type="submit" block size="large" :loading="isSubmitting">
                Verificar código
            </BaseButton>
        </form>

        <div style="text-align: center; margin-top: 16px">
            <BaseButton
                v-if="cooldown === 0"
                variant="tertiary"
                :loading="isResending"
                @click="resendCode"
            >
                {{ isResending ? 'Reenviando…' : 'Reenviar código' }}
            </BaseButton>
            <p v-else class="auth-footer-text" style="margin-top: 8px">
                Podés reenviar en
                <span style="color: var(--auth-accent); font-family: 'JetBrains Mono', monospace; font-weight: 600">
                    {{ cooldown }}s
                </span>
            </p>
        </div>

        <p class="auth-footer-text">
            <RouterLink to="/login" class="auth-link">← Volver al inicio de sesión</RouterLink>
        </p>
    </div>
</template>

<style scoped>
.verify-icon {
    position: relative;
    width: 72px;
    height: 72px;
    margin: 0 auto 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(240, 6, 20, 0.07);
    border: 1px solid rgba(240, 6, 20, 0.18);
    border-radius: 20px;
}

.verify-icon-ring {
    position: absolute;
    inset: -6px;
    border-radius: 24px;
    border: 1px solid rgba(240, 6, 20, 0.10);
    animation: pulse-ring 2.5s ease infinite;
}

@keyframes pulse-ring {
    0%, 100% { opacity: 0.4; transform: scale(1); }
    50%       { opacity: 0.9; transform: scale(1.04); }
}

.err-enter-active { transition: all 0.2s ease; }
.err-enter-from  { opacity: 0; transform: translateY(-4px); }
.err-enter-to    { opacity: 1; transform: translateY(0); }
</style>
