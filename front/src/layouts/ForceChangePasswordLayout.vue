<script setup lang="ts">
import { theme } from 'ant-design-vue'
import { useAuthStore } from '@/modules/auth/stores/auth.store'

const authStore = useAuthStore()

const layoutTheme = {
    algorithm: theme.defaultAlgorithm,
    token: {
        colorPrimary:         '#f00614',
        colorTextLightSolid:  '#FFFFFF',
        colorBgContainer:     '#FFFFFF',
        colorBgElevated:      '#FFFFFF',
        colorBorder:          '#E5E7EB',
        colorBorderSecondary: '#F3F4F6',
        colorText:            '#1A2433',
        colorTextSecondary:   '#6B7A8D',
        colorTextPlaceholder: 'rgba(107, 122, 141, 0.55)',
        colorLink:            '#f00614',
        colorLinkHover:       '#c8010f',
        borderRadius:         8,
        fontFamily:           '"Figtree", system-ui, sans-serif',
        controlHeight:        44,
    },
    components: {
        Input:  { colorBgContainer: '#FFFFFF' },
        Button: { fontWeight: 600 },
    },
}
</script>

<template>
    <a-config-provider :theme="layoutTheme">
        <div class="fcp-root">
            <!-- Subtle background grid -->
            <div class="fcp-grid" aria-hidden="true" />

            <!-- Logo -->
            <div class="fcp-logo">
                <div class="fcp-logo-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2C9.5 2 7.5 3.8 7.5 6s2 4 4.5 4 4.5-1.8 4.5-4S14.5 2 12 2Z" fill="#f00614" opacity="0.9"/>
                        <circle cx="6.5" cy="6.5" r="2" fill="#f00614" opacity="0.6"/>
                        <circle cx="17.5" cy="6.5" r="2" fill="#f00614" opacity="0.6"/>
                        <circle cx="4" cy="11" r="1.8" fill="#f00614" opacity="0.5"/>
                        <circle cx="20" cy="11" r="1.8" fill="#f00614" opacity="0.5"/>
                        <path d="M12 13c-4.5 0-8 2.5-8 6 0 2 3.5 3 8 3s8-1 8-3c0-3.5-3.5-6-8-6Z" fill="#f00614" opacity="0.8"/>
                    </svg>
                </div>
                <span class="fcp-logo-name">Vet<span>Alert</span></span>
            </div>

            <!-- Card -->
            <div class="fcp-card">
                <!-- Security badge -->
                <div class="fcp-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" fill="#f00614" opacity="0.9"/>
                        <path d="M9 12l2 2 4-4" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Acción de seguridad requerida
                </div>

                <RouterView />

                <!-- Session info -->
                <div v-if="authStore.user" class="fcp-session">
                    Sesión activa como
                    <strong>{{ authStore.user.email }}</strong>
                </div>
            </div>
        </div>
    </a-config-provider>
</template>

<style scoped>
.fcp-root {
    min-height: 100vh;
    background: #F5F6F8;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px;
    position: relative;
    overflow: hidden;
}

/* Subtle dot grid */
.fcp-grid {
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(240, 6, 20, 0.05) 1px, transparent 1px);
    background-size: 32px 32px;
    pointer-events: none;
}

/* Radial glow at center */
.fcp-root::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -60%);
    width: 600px;
    height: 600px;
    background: radial-gradient(ellipse, rgba(240, 6, 20, 0.04) 0%, transparent 70%);
    pointer-events: none;
}

/* Logo */
.fcp-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 32px;
    position: relative;
    z-index: 1;
}

.fcp-logo-icon {
    width: 40px;
    height: 40px;
    background: rgba(240, 6, 20, 0.08);
    border: 1px solid rgba(240, 6, 20, 0.18);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.fcp-logo-name {
    font-size: 20px;
    font-weight: 700;
    color: #1A2433;
    letter-spacing: -0.3px;
    font-family: 'Figtree', system-ui, sans-serif;
}

.fcp-logo-name span {
    color: #f00614;
}

/* Card */
.fcp-card {
    width: 100%;
    max-width: 420px;
    background: #FFFFFF;
    border: 1px solid #E5E7EB;
    border-radius: 16px;
    padding: 32px;
    position: relative;
    z-index: 1;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08), 0 1px 4px rgba(0, 0, 0, 0.04);
}

/* Security badge */
.fcp-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(240, 6, 20, 0.07);
    border: 1px solid rgba(240, 6, 20, 0.18);
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 11px;
    font-weight: 600;
    color: #c8010f;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-bottom: 24px;
}

/* Session info */
.fcp-session {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #F3F4F6;
    font-size: 12px;
    color: #6B7A8D;
    text-align: center;
}

.fcp-session strong {
    color: #1A2433;
    font-weight: 500;
}
</style>
