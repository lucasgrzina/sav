import Antd from 'ant-design-vue'
import 'ant-design-vue/dist/reset.css'
import dayjs from 'dayjs'
import 'dayjs/locale/es'
import type { App } from 'vue'

export function setupAntd(app: App) {
  // Global dayjs locale so Ant Design date/time pickers render month and day names in Spanish.
  dayjs.locale('es')
  app.use(Antd)
}
