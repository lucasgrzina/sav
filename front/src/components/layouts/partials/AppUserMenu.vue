<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { LogoutOutlined } from '@ant-design/icons-vue'
import { useAuth } from '@/composables/useAuth'
import { useTheme } from '@/core/composables/useTheme'
import { PALETTE_OPTIONS } from '@/core/themes/palettes'

const router = useRouter()
const { user, logout } = useAuth()
const { isLight, palette, toggle, setPalette } = useTheme()

const isOpen = ref(false)
const wrapperRef = ref<HTMLElement | null>(null)

async function handleLogout() {
  isOpen.value = false
  await logout()
  router.push('/login')
}

function onDocClick(e: MouseEvent) {
  if (wrapperRef.value && !wrapperRef.value.contains(e.target as Node)) {
    isOpen.value = false
  }
}

onMounted(()   => document.addEventListener('click', onDocClick, true))
onUnmounted(() => document.removeEventListener('click', onDocClick, true))
</script>

<template>
  <div class="dash-user-wrapper" ref="wrapperRef">
    <div class="dash-user-chip" @click="isOpen = !isOpen">
      <div class="dash-avatar">
        {{ user?.first_name?.charAt(0)?.toUpperCase() ?? '?' }}
      </div>
      <span class="dash-user-name">
        {{ user?.first_name }} {{ user?.last_name }}
      </span>
    </div>

    <Transition name="menu-pop">
      <div v-if="isOpen" class="dash-user-menu">
        <div class="dash-user-menu-header">
          <div class="dash-user-menu-name">{{ user?.first_name }} {{ user?.last_name }}</div>
          <div class="dash-user-menu-email">{{ user?.email }}</div>
        </div>

        <div class="dash-palette-row">
          <span class="dash-palette-label">Color</span>
          <div class="dash-palette-swatches">
            <button
              v-for="opt in PALETTE_OPTIONS"
              :key="opt.key"
              class="dash-swatch"
              :class="{ 'is-active': palette === opt.key }"
              :style="{ '--swatch-color': opt.color }"
              :title="opt.label"
              @click.stop="setPalette(opt.key)"
            />
          </div>
        </div>

        <div class="dash-theme-row">
          <span class="dash-theme-label">
            <svg v-if="isLight" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="4"/>
              <line x1="12" y1="2"     x2="12" y2="6"/>
              <line x1="12" y1="18"    x2="12" y2="22"/>
              <line x1="4.22" y1="4.22"   x2="7.05" y2="7.05"/>
              <line x1="16.95" y1="16.95" x2="19.78" y2="19.78"/>
              <line x1="2"  y1="12"    x2="6"  y2="12"/>
              <line x1="18" y1="12"    x2="22" y2="12"/>
              <line x1="4.22" y1="19.78"  x2="7.05" y2="16.95"/>
              <line x1="16.95" y1="7.05"  x2="19.78" y2="4.22"/>
            </svg>
            <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            Modo claro
          </span>
          <label class="theme-switch" @click.stop>
            <input type="checkbox" :checked="isLight" @change="toggle" />
            <span class="theme-switch-track" />
          </label>
        </div>

        <button class="dash-user-menu-item dash-user-menu-item--danger" @click="handleLogout">
          <LogoutOutlined />
          Cerrar sesión
        </button>
      </div>
    </Transition>
  </div>
</template>
