<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

const showBanner = ref(false)

function handleStorageEvent(event: StorageEvent): void {
  if (event.key !== 'vet') return

  try {
    const newValue = event.newValue ? JSON.parse(event.newValue) : null
    const oldValue = event.oldValue ? JSON.parse(event.oldValue) : null

    const newSlug = newValue?.lastVisitedGuid ?? null
    const oldSlug = oldValue?.lastVisitedGuid ?? null

    if (newSlug !== oldSlug && newSlug !== null) {
      showBanner.value = true
    }
  } catch {
    // JSON parse failed — ignore
  }
}

onMounted(() => {
  window.addEventListener('storage', handleStorageEvent)
})

onUnmounted(() => {
  window.removeEventListener('storage', handleStorageEvent)
})

function reload(): void {
  window.location.reload()
}
</script>

<template>
  <Transition name="banner-slide">
    <div v-if="showBanner" class="vet-changed-banner">
      <span class="vet-changed-banner__text">
        Cambiaste de veterinaria en otra pestaña.
      </span>
      <button class="vet-changed-banner__btn" @click="reload">
        Recargar
      </button>
      <button class="vet-changed-banner__close" @click="showBanner = false" aria-label="Cerrar">
        &times;
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.vet-changed-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 10px 16px;
  background: var(--dt-warning-bg, #2a2200);
  border-bottom: 1px solid var(--dt-warning, #faad14);
  font-size: 13px;
  color: var(--dt-warning, #faad14);
}

.vet-changed-banner__text {
  flex: 1;
  text-align: center;
}

.vet-changed-banner__btn {
  background: var(--dt-warning, #faad14);
  color: #000;
  border: none;
  border-radius: 4px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s;
}

.vet-changed-banner__btn:hover {
  opacity: 0.85;
}

.vet-changed-banner__close {
  background: none;
  border: none;
  color: var(--dt-warning, #faad14);
  font-size: 18px;
  cursor: pointer;
  line-height: 1;
  padding: 0 4px;
  opacity: 0.7;
}

.vet-changed-banner__close:hover {
  opacity: 1;
}

.banner-slide-enter-active,
.banner-slide-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}

.banner-slide-enter-from,
.banner-slide-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
