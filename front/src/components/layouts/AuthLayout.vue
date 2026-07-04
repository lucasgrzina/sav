<script setup lang="ts">
import { theme } from 'ant-design-vue'
import AuthBrandPanel from '@/modules/auth/components/AuthBrandPanel.vue'

const authTheme = {
    algorithm: theme.darkAlgorithm,
    token: {
        colorPrimary:         '#1AE5A0',
        colorTextLightSolid:  '#060F1C',
        colorBgContainer:     '#0F2035',
        colorBgElevated:      '#162840',
        colorBorder:          'rgba(26, 229, 160, 0.18)',
        colorBorderSecondary: 'rgba(26, 229, 160, 0.1)',
        colorText:            '#C8E2EF',
        colorTextSecondary:   '#6B8CAE',
        colorTextPlaceholder: 'rgba(107, 140, 174, 0.5)',
        colorLink:            '#1AE5A0',
        colorLinkHover:       '#2DFFB5',
        borderRadius:         8,
        fontFamily:           '"Figtree", system-ui, sans-serif',
        controlHeight:        44,
    },
    components: {
        Input:    { colorBgContainer: 'rgba(26,229,160,0.04)' },
        Button:   { fontWeight: 600 },
        Checkbox: { colorBgContainer: 'transparent' },
        Steps:    { colorPrimary: '#1AE5A0' },
    },
}
</script>

<template>
    <a-config-provider :theme="authTheme">
        <div class="auth-root">
            <div class="auth-brand-panel">
                <AuthBrandPanel />
            </div>
            <div class="auth-form-panel">
                <div class="auth-form-inner">
                    <div class="auth-mobile-logo">Vet<span>Alert</span></div>
                    <RouterView />
                </div>
            </div>
        </div>
    </a-config-provider>
</template>
