import { ref, shallowRef } from 'vue'
import type { Ref } from 'vue'

export function useModal<T = unknown>() {
  const isOpen = shallowRef(false)
  const payload = ref<T | null>(null) as Ref<T | null>

  function open(data?: T) {
    payload.value = data ?? null
    isOpen.value = true
  }

  function close() {
    isOpen.value = false
    payload.value = null
  }

  return { isOpen, payload, open, close }
}
