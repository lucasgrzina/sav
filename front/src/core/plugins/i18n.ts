import { i18n } from '@/i18n'
import type { App } from 'vue'

export function setupI18n(app: App) {
  app.use(i18n)
}
