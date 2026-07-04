import { computed, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useLocalStorage } from '@vueuse/core'
import { useResponsive } from '@/core/composables/useResponsive'

export function useSidebar() {
  const { isMobile } = useResponsive()
  const route = useRoute()

  const desktopCollapsed = useLocalStorage('sidebar_collapsed', false)
  const mobileCollapsed = shallowRef(true)

  // Al pasar a mobile, forzar cierre
  watch(isMobile, (nowMobile) => {
    if (nowMobile) mobileCollapsed.value = true
  })

  // En mobile, cerrar al cambiar de ruta
  watch(() => route.path, () => {
    if (isMobile.value) mobileCollapsed.value = true
  })

  const collapsed = computed({
    get: () => (isMobile.value ? mobileCollapsed.value : desktopCollapsed.value),
    set: (value: boolean) => {
      if (isMobile.value) {
        mobileCollapsed.value = value
      } else {
        desktopCollapsed.value = value
      }
    },
  })

  return { collapsed }
}
