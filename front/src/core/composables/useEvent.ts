import { onMounted, onUnmounted } from 'vue'
import eventBus from '@/core/services/event-bus.service'

export function useEvent<T = unknown>(event: string, callback: (payload: T) => void): void {
  let unsubscribe: (() => void) | null = null

  onMounted(() => {
    unsubscribe = eventBus.on<T>(event, callback)
  })

  onUnmounted(() => {
    if (unsubscribe) {
      unsubscribe()
      unsubscribe = null
    }
  })
}
