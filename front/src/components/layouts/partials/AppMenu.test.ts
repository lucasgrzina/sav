import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AppMenu from './AppMenu.vue'
import { i18n } from '@/i18n'

const s = vi.hoisted(() => ({ permissions: [] as string[] }))

vi.mock('vue-router', () => ({ useRoute: () => ({ path: '/' }) }))
vi.mock('@/core/composables/usePermissions', () => ({
  usePermission: () => ({ can: (p: string) => s.permissions.includes(p) }),
}))

function mountMenu() {
  return mount(AppMenu, {
    props: { collapsed: false },
    global: { plugins: [i18n], stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })
}

describe('AppMenu dashboard item', () => {
  beforeEach(() => {
    s.permissions = []
  })

  it('hides the Dashboard item without dashboard.admin.read', () => {
    expect(mountMenu().text()).not.toContain('Dashboard')
  })

  it('shows the Dashboard item with dashboard.admin.read', () => {
    s.permissions = ['dashboard.admin.read']

    expect(mountMenu().text()).toContain('Dashboard')
  })
})
