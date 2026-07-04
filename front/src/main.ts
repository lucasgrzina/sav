import { createApp } from 'vue'
import { setupPinia } from '@/core/plugins/pinia'
import { setupVueQuery } from '@/core/plugins/vue-query'
import { setupAntd } from '@/core/plugins/antd'
import { setupI18n } from '@/core/plugins/i18n'
import { router } from '@/router'
import App from '@/App.vue'
import './styles/main.css'

async function initApp() {
  const app = createApp(App)

  setupPinia(app)
  setupVueQuery(app)
  setupI18n(app)
  setupAntd(app)

  app.use(router)
  app.mount('#app')
}

initApp()
