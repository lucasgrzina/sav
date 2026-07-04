import { createPinia } from 'pinia'
import piniaPersist from 'pinia-plugin-persistedstate'
import type { App } from 'vue'

export function setupPinia(app: App) {
  const pinia = createPinia()
  pinia.use(piniaPersist)
  app.use(pinia)
}
