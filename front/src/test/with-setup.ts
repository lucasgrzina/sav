import { createApp, defineComponent, h } from 'vue'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'

export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
}

/** Runs a composable inside a real component setup context (Vue Query plugin installed). */
export function withSetup<T>(composable: () => T, queryClient: QueryClient = createTestQueryClient()) {
  let result!: T
  const app = createApp(
    defineComponent({
      setup() {
        result = composable()
        return () => h('div')
      },
    }),
  )
  app.use(VueQueryPlugin, { queryClient })
  app.mount(document.createElement('div'))
  return { result, app, queryClient }
}
