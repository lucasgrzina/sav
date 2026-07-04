<script setup lang="ts">
import { theme } from 'ant-design-vue';
import AuthBrandPanel from '@/modules/auth/components/AuthBrandPanel.vue';

const authTheme = {
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
        Input:    { colorBgContainer: '#FFFFFF' },
        Button:   { fontWeight: 600 },
        Checkbox: { colorBgContainer: '#FFFFFF' },
        Steps:    { colorPrimary: '#f00614' },
    },
};
</script>

<template>
    <a-config-provider :theme="authTheme">
        <div class="auth-root">
            <!-- Left: Brand panel -->
            <div class="auth-brand-panel">
                <AuthBrandPanel />
            </div>

            <!-- Right: Form panel -->
            <div class="auth-form-panel">
                <div class="auth-form-inner">
                    <div class="auth-mobile-logo">Vet<span>Alert</span></div>
                    <RouterView />
                </div>
            </div>
        </div>
    </a-config-provider>
</template>
