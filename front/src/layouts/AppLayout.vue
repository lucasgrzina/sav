<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { APP_NAME } from '@/core/constants/app'
import { useTheme } from '@/core/composables/useTheme'
import { useSidebar } from '@/core/composables/useSidebar'
import { MenuOutlined } from '@ant-design/icons-vue'
import AppSidebar from '@/components/layouts/partials/AppSidebar.vue'
import NotificationBell from '@/modules/notifications/components/NotificationBell.vue'
import AppUserMenu from '@/components/layouts/partials/AppUserMenu.vue'
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue'
import ExportFormatSelector from '@/components/shared/ExportFormatSelector.vue'
import ExportDrawer from '@/modules/exports/components/ExportDrawer.vue'
import { useExportsUiStore } from '@/modules/exports/stores/exports-ui.store'
import { getUserSettingsApi } from '@/modules/settings/api/settings.api'
import { useSettingsStore } from '@/modules/settings/stores/settings.store'

const route    = useRoute()
const { dashTheme, isLight, palette, applySettings } = useTheme()
const { collapsed } = useSidebar()
const exportsUiStore = useExportsUiStore()

const pageTitle = computed(() => (route.meta.title as string | undefined) ?? APP_NAME)

onMounted(() => {
    getUserSettingsApi()
        .then((settings) => {
            useSettingsStore().apply(settings)
            applySettings(settings)
        })
        .catch(() => {})
})
</script>

<template>
    <a-config-provider :theme="dashTheme">
        <div class="dash-root" :class="[{ light: isLight }, `palette-${palette}`]">

            <Transition name="dash-overlay">
                <div v-if="!collapsed" class="dash-overlay" @click="collapsed = true" />
            </Transition>

            <AppSidebar v-model:collapsed="collapsed" />

            <div class="dash-main">
                <header class="dash-header">
                    <button class="dash-menu-btn" title="Menú" @click="collapsed = !collapsed">
                        <MenuOutlined />
                    </button>
                    <h1 class="dash-header-title">{{ pageTitle }}</h1>
                    <div class="dash-header-right">
                        <NotificationBell />
                        <AppUserMenu />
                    </div>
                </header>

                <main class="dash-content">
                    <RouterView />
                </main>
            </div>

            <ConfirmDialog />
            <ExportFormatSelector />
            <ExportDrawer v-model="exportsUiStore.isModalOpen" />
        </div>
    </a-config-provider>
</template>
