import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type { Ref } from 'vue'
import { mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import { i18n } from '@/i18n'
import DashboardPage from './DashboardPage.vue'
import type { AdminDashboardData } from '../types/dashboard.types'

const s = vi.hoisted(() => ({
  permissions: [] as string[],
  data: null as unknown as Ref<AdminDashboardData | undefined>,
  isLoading: null as unknown as Ref<boolean>,
  error: null as unknown as Ref<Error | null>,
  refetch: vi.fn(),
}))

vi.mock('@/core/composables/usePermissions', () => ({
  usePermission: () => ({ can: (p: string) => s.permissions.includes(p) }),
}))
vi.mock('../composables/useAdminDashboard', () => ({
  useAdminDashboard: () => ({ data: s.data, isLoading: s.isLoading, error: s.error, refetch: s.refetch }),
}))

function build(overrides: Partial<AdminDashboardData> = {}): AdminDashboardData {
  return {
    generated_at: '2026-10-14T12:00:00Z',
    vets: { active: 10, pending: 2, suspended: 1, total: 13 },
    totals: { clients: 20, establishments: 30, animals: 1500, active_programs: 4 },
    sent_last_7d: [
      { date: '2026-10-08', count: 0 },
      { date: '2026-10-14', count: 5 },
    ],
    delivery_30d: {
      rate: 92.5,
      by_channel: [{ channel: 'whatsapp', ok: 37, failed: 3, rate: 92.5 }],
    },
    ...overrides,
  }
}

function mountPage() {
  return mount(DashboardPage, {
    global: { plugins: [Antd, i18n, createTestingPinia({ createSpy: vi.fn })] },
  })
}

describe('DashboardPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    s.permissions = ['dashboard.admin.read']
    s.data = ref(build())
    s.isLoading = ref(false)
    s.error = ref(null)
  })

  it('renders all metrics, the sent chart and the delivery rates', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Empresas activas')
    expect(wrapper.text()).toContain('1.500')
    expect(wrapper.text()).toContain('14/10')
    expect(wrapper.get('[data-testid="delivery-overall"]').text()).toBe('92,5 %')
    expect(wrapper.get('[data-testid="channel-rate"]').text()).toBe('92,5 %')
    expect(wrapper.text()).toContain('WhatsApp')
    expect(wrapper.find('[data-testid="generated-at"]').exists()).toBe(true)
  })

  it('does not render the metrics while loading', () => {
    s.isLoading = ref(true)
    s.data = ref(undefined)

    expect(mountPage().text()).not.toContain('Empresas activas')
  })

  it('shows the error state and retries on demand', async () => {
    s.error = ref(new Error('boom'))
    s.data = ref(undefined)

    const wrapper = mountPage()
    expect(wrapper.text()).toContain('No se pudieron cargar las estadísticas.')

    await wrapper.get('button').trigger('click')
    expect(s.refetch).toHaveBeenCalled()
  })

  it('renders zeros, empty chart/delivery states and a dash for a null rate', () => {
    s.data = ref(build({
      vets: { active: 0, pending: 0, suspended: 0, total: 0 },
      totals: { clients: 0, establishments: 0, animals: 0, active_programs: 0 },
      sent_last_7d: [{ date: '2026-10-14', count: 0 }],
      delivery_30d: { rate: null, by_channel: [] },
    }))

    const wrapper = mountPage()

    expect(wrapper.text()).toContain('No se enviaron avisos en los últimos 7 días.')
    expect(wrapper.text()).toContain('Sin envíos en los últimos 30 días.')
    expect(wrapper.get('[data-testid="delivery-overall"]').text()).toBe('—')
  })

  it('shows a dash for the rate of a channel without attempts', () => {
    s.data = ref(build({
      delivery_30d: { rate: null, by_channel: [{ channel: 'email', ok: 0, failed: 0, rate: null }] },
    }))

    expect(mountPage().get('[data-testid="channel-rate"]').text()).toBe('—')
  })

  it('does not show stats without the permission', () => {
    s.permissions = []

    const wrapper = mountPage()

    expect(wrapper.find('[data-testid="no-access"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Empresas activas')
  })
})
