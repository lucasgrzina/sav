<script setup lang="ts">
import { watch, onMounted } from 'vue'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import socketService from '@/core/services/socket.service'

const authStore = useAuthStore()

onMounted(() => {
  if (authStore.isAuthenticated && authStore.user?.id) {
    socketService.connect(authStore.user.id)
  }
})

watch(
  () => [authStore.isAuthenticated, authStore.user?.id] as const,
  ([isAuth, userId]) => {
    if (isAuth && userId) {
      socketService.connect(userId)
    } else {
      socketService.disconnect()
    }
  },
)
</script>

<template>
    <RouterView />
</template>
