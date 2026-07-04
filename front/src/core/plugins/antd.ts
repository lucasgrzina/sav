import Antd from 'ant-design-vue'
import 'ant-design-vue/dist/reset.css'
import type { App } from 'vue'

export function setupAntd(app: App) {
  app.use(Antd)
}
