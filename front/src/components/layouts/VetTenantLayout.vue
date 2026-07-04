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
import VetSwitcher from '@/components/shared/VetSwitcher.vue'
import VetChangedBanner from '@/components/shared/VetChangedBanner.vue'
import { useVetTenant } from '@/modules/vets/composables/useVetTenant'
import { useUserVets } from '@/modules/vets/composables/useUserVets'
import { getUserSettingsApi } from '@/modules/settings/api/settings.api'
import { useSettingsStore } from '@/modules/settings/stores/settings.store'

const route = useRoute()
const { dashTheme, isLight, palette, applySettings } = useTheme()
const { collapsed } = useSidebar()

const pageTitle = computed(() => (route.meta.title as string | undefined) ?? APP_NAME)

useUserVets()

const { isLoading } = useVetTenant()

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

      <VetChangedBanner />

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
            <VetSwitcher />
            <NotificationBell />
            <AppUserMenu />
          </div>
        </header>

        <main class="dash-content">
          <template v-if="isLoading">
            <div class="vet-layout-skeleton">
              <a-skeleton active :paragraph="{ rows: 4 }" />
            </div>
          </template>

          <RouterView v-else />
        </main>
      </div>

      <ConfirmDialog />
    </div>
  </a-config-provider>
</template>

<style scoped>
.dash-root {
  display: flex;
  min-height: 100vh;
  background: var(--dt-bg, #07111F);
}

.dash-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  z-index: 99;
}

.dash-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.dash-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 24px;
  height: 56px;
  border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  background: var(--dt-card, #0E2038);
  position: sticky;
  top: 0;
  z-index: 10;
}

.dash-menu-btn {
  background: none;
  border: none;
  color: var(--dt-muted, #6B8CAE);
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
  border-radius: 4px;
  transition: color 0.15s;
}
.dash-menu-btn:hover { color: var(--dt-text, #C8E2EF); }

.dash-header-title {
  flex: 1;
  font-family: 'Syne', sans-serif;
  font-size: 16px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.dash-header-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.dash-content {
  flex: 1;
  padding: 24px;
  overflow-y: auto;
}

.vet-layout-skeleton {
  max-width: 800px;
  padding: 8px;
}
</style>
