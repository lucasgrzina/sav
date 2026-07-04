<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { BellOutlined } from '@ant-design/icons-vue'
import { useNotifications } from '../composables/useNotifications'
import { useEvent } from '@/core/composables/useEvent'
import { useExportDownload } from '@/modules/exports/composables/useExportDownload'
import type { NotificationItem, NotificationRealtimePayload } from '../types/notification.types'

const { unreadCount, latest, fetchLatest, markAsRead, markAllAsRead, pushRealtime } =
  useNotifications()

const router = useRouter()
const { downloadExport } = useExportDownload()

const isOpen = ref(false)
const wrapperRef = ref<HTMLElement | null>(null)

onMounted(() => {
  fetchLatest()
  document.addEventListener('click', onDocClick, true)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick, true)
})

useEvent<NotificationRealtimePayload>('news', (payload) => {
  pushRealtime(payload)
})

function onDocClick(e: MouseEvent) {
  if (wrapperRef.value && !wrapperRef.value.contains(e.target as Node)) {
    isOpen.value = false
  }
}

async function handleItemClick(item: NotificationItem) {
  markAsRead(item.guid)

  const subtype = item.payload?.subtype

  if (subtype === 'export_ready' && item.payload?.export_guid) {
    isOpen.value = false
    await downloadExport(item.payload.export_guid as string)
    return
  }

  if (item.payload?.url && subtype !== 'export_started' && subtype !== 'export_failed') {
    isOpen.value = false
    router.push(item.payload.url as string)
  }
}
</script>

<template>
  <div class="notif-bell-wrapper" ref="wrapperRef">
    <button
      class="dash-icon-btn notif-bell-btn"
      title="Notificaciones"
      @click="isOpen = !isOpen"
    >
      <BellOutlined />
      <span v-if="unreadCount > 0" class="notif-badge">
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <Transition name="notif-pop">
      <div v-if="isOpen" class="notif-dropdown">
        <div class="notif-dropdown-header">
          <span class="notif-dropdown-title">Notificaciones</span>
          <button
            v-if="unreadCount > 0"
            class="notif-mark-all-btn"
            @click="markAllAsRead"
          >
            Marcar todas como leídas
          </button>
        </div>

        <div class="notif-dropdown-list">
          <div v-if="latest.length === 0" class="notif-empty">
            No tenés notificaciones.
          </div>

          <div
            v-for="item in latest"
            :key="item.guid"
            class="notif-item"
            :class="{ 'notif-item--unread': !item.read_at }"
            @click="handleItemClick(item)"
          >
            <div class="notif-item-title">{{ item.payload?.title }}</div>
            <div class="notif-item-desc">{{ item.payload?.description }}</div>
            <div class="notif-item-time">{{ item.created_at }}</div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
/* ── Wrapper posicionamiento relativo ─────────────────────────── */
.notif-bell-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
}

/* ── Badge de conteo no leído ─────────────────────────────────── */
.notif-bell-btn {
  position: relative;
}

.notif-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: 999px;
  background: var(--dt-danger);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  line-height: 16px;
  text-align: center;
  pointer-events: none;
}

/* ── Dropdown ─────────────────────────────────────────────────── */
.notif-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 200;
  width: 340px;
  background: var(--dt-card);
  border: 1px solid var(--dt-border2);
  border-radius: var(--dt-radius-lg);
  box-shadow: 0 8px 32px var(--dt-shadow);
  overflow: hidden;
}

/* ── Header del dropdown ──────────────────────────────────────── */
.notif-dropdown-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid var(--dt-border);
}

.notif-dropdown-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--dt-title);
  letter-spacing: 0.01em;
}

.notif-mark-all-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 11px;
  color: var(--dt-accent-t, var(--dt-accent));
  padding: 0;
  transition: opacity 0.15s;
}

.notif-mark-all-btn:hover {
  opacity: 0.75;
}

/* ── Lista de notificaciones ──────────────────────────────────── */
.notif-dropdown-list {
  max-height: 380px;
  overflow-y: auto;
}

/* ── Estado vacío ─────────────────────────────────────────────── */
.notif-empty {
  padding: 32px 16px;
  text-align: center;
  font-size: 13px;
  color: var(--dt-muted);
}

/* ── Ítem individual ──────────────────────────────────────────── */
.notif-item {
  padding: 12px 16px;
  border-bottom: 1px solid var(--dt-border);
  cursor: pointer;
  transition: background 0.15s;
}

.notif-item:last-child {
  border-bottom: none;
}

.notif-item:hover {
  background: var(--dt-hover);
}

.notif-item--unread {
  background: var(--dt-accent-bg);
}

.notif-item--unread:hover {
  background: var(--dt-active);
}

.notif-item-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--dt-title);
  margin-bottom: 2px;
}

.notif-item-desc {
  font-size: 12px;
  color: var(--dt-text);
  line-height: 1.45;
  margin-bottom: 4px;
}

.notif-item-time {
  font-size: 11px;
  color: var(--dt-muted);
}

/* ── Transición de apertura (mismo estilo que AppUserMenu) ────── */
.notif-pop-enter-active,
.notif-pop-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.notif-pop-enter-from,
.notif-pop-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.97);
}
</style>
