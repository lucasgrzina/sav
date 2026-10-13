import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type { Ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import ProgramAlertsTab from './ProgramAlertsTab.vue'
import type { ProgramAlertLine, ProgramAlertsFilters } from '../../../types/program-alerts.types'

const s = vi.hoisted(() => ({
  data: null as unknown as Ref<unknown>,
  isError: null as unknown as Ref<boolean>,
  isLoading: null as unknown as Ref<boolean>,
  isMobile: null as unknown as Ref<boolean>,
  refetch: vi.fn(),
  calls: [] as { filters: Ref<ProgramAlertsFilters>; page: Ref<number> }[],
}))

vi.mock('../../../composables/useProgramAlerts', () => ({
  PROGRAM_ALERTS_PER_PAGE: 25,
  useProgramAlerts: (_v: unknown, _g: unknown, filters: unknown, page: unknown) => {
    s.calls.push({ filters: filters as Ref<ProgramAlertsFilters>, page: page as Ref<number> })
    return { data: s.data, isError: s.isError, isLoading: s.isLoading, isFetching: ref(false), refetch: s.refetch }
  },
}))
vi.mock('@/core/composables/useResponsive', () => ({
  useResponsive: () => ({ isMobile: s.isMobile }),
}))
vi.mock('@/core/composables/usePermissions', () => ({
  usePermission: () => ({ can: () => true }),
}))

function line(overrides: Partial<ProgramAlertLine> = {}): ProgramAlertLine {
  return {
    key: 'a1:p1',
    alert_guid: 'a1',
    type: 'program.task_due',
    scheduled_at_local: {
      iso: '2026-09-24T09:00:00-03:00', date: '2026-09-24', time: '09:00',
      weekday: 'jueves', weekday_short: 'jue', label: 'jue 24/09/2026 09:00',
    },
    scheduled_at_utc: '2026-09-24T12:00:00Z',
    recipient: { profile_guid: 'p1', name: 'Maria Perez', role: 'client-manager' },
    channel: 'whatsapp',
    state: 'sent',
    bucket: 'sent',
    failure_code: null,
    cancel_reason: null,
    fallback: { used: false, email_state: null },
    destination_masked: '+54 ••• •••• 4821',
    sent_at: '2026-09-24T12:00:00Z',
    delivered_at: null,
    task: { protocol_task_guid: 't1', description: 'Colecta de embriones', task_date: '2026-09-24', task_time: '09:00' },
    message: null,
    rendered_body: null,
    group: { target_guid: 'g1', target_date: '2026-09-23', label: 'grupo del 23/09/2026' },
    contact: null,
    attempts: [],
    retry: null,
    ...overrides,
  }
}

function payload(lines: ProgramAlertLine[], counters = { scheduled: 0, sent: 1, problem: 0, cancelled: 0, total: 1 }) {
  return {
    data: lines,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: lines.length,
    meta: {
      counters, warnings: [], timezone: 'America/Argentina/Buenos_Aires',
      out_of_sync: false, multi_group: false, program_cancelled_at: null, last_reconciled_at: null,
    },
  }
}

function mountTab() {
  return mount(ProgramAlertsTab, {
    props: {
      vetGuid: 'vet-1',
      guid: 'prog-1',
      targets: [{ guid: 'g1', target_date: '2026-09-23', animals: [] }],
      managers: [{ guid: 'p1', name: 'Maria Perez', role: 'client-manager', origin: 'client' }],
      editable: true,
    },
    global: {
      plugins: [Antd, createTestingPinia({ stubActions: false, createSpy: vi.fn })],
      stubs: { PermissionGuard: { template: '<div><slot /></div>' } },
    },
  })
}

describe('ProgramAlertsTab', () => {
  beforeEach(() => {
    s.data = ref(payload([line()]))
    s.isError = ref(false)
    s.isLoading = ref(false)
    s.isMobile = ref(false)
    s.refetch.mockReset()
    s.calls.length = 0
  })

  it('renders the counters and the lines on desktop', () => {
    s.data = ref(payload([line()], { scheduled: 2, sent: 1, problem: 3, cancelled: 0, total: 6 }))
    const wrapper = mountTab()

    expect(wrapper.get('[data-testid="alerts-status-all"]').text()).toContain('6')
    expect(wrapper.get('[data-testid="alerts-status-scheduled"]').text()).toContain('2')
    expect(wrapper.get('[data-testid="alerts-status-problem"]').text()).toContain('3')
    expect(wrapper.find('[data-testid="alerts-table"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Maria Perez')
    expect(wrapper.text()).toContain('Aún sin confirmación de entrega')
    expect(wrapper.text()).not.toContain('confirmado')
  })

  it('uses cards instead of the table on mobile', () => {
    s.isMobile = ref(true)
    const wrapper = mountTab()

    expect(wrapper.findAll('[data-testid="alert-card"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="alerts-table"]').exists()).toBe(false)
  })

  it('queries with the selected status and resets the page', async () => {
    const wrapper = mountTab()
    await wrapper.get('[data-testid="alerts-status-problem"]').trigger('click')
    await flushPromises()

    const last = s.calls[s.calls.length - 1]
    expect(last.filters.value).toMatchObject({ status: 'problem', profile: null, target: null, type: null })
    expect(last.page.value).toBe(1)
  })

  it('shows the program empty state with the edit button when there are no alerts at all', async () => {
    s.data = ref(payload([], { scheduled: 0, sent: 0, problem: 0, cancelled: 0, total: 0 }))
    const wrapper = mountTab()

    expect(wrapper.get('[data-testid="alerts-empty"]').text()).toContain('Este programa todavía no tiene avisos programados.')
    await wrapper.findComponent({ name: 'BaseButton' }).trigger('click')
    expect(wrapper.emitted('edit')).toHaveLength(1)
  })

  it('shows the filter empty state with the problem-specific copy', async () => {
    s.data = ref(payload([], { scheduled: 0, sent: 3, problem: 0, cancelled: 0, total: 3 }))
    const wrapper = mountTab()
    await wrapper.get('[data-testid="alerts-status-problem"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="alerts-filter-empty"]').text()).toContain('No hay avisos con problemas.')
  })

  it('shows the error state and retries', async () => {
    s.data = ref(undefined)
    s.isError = ref(true)
    const wrapper = mountTab()

    expect(wrapper.text()).toContain('No pudimos cargar los avisos.')
    await wrapper.find('button.ant-btn-primary').trigger('click')
    expect(s.refetch).toHaveBeenCalled()
  })

  it('maps failure codes to readable copy and never leaks technical text', () => {
    s.data = ref(payload([
      line({ state: 'failed', bucket: 'problem', failure_code: 'invalid_number' }),
    ], { scheduled: 0, sent: 0, problem: 1, cancelled: 0, total: 1 }))
    const wrapper = mountTab()

    expect(wrapper.text()).toContain('El número no tiene WhatsApp o no es válido.')
    expect(wrapper.text()).not.toContain('invalid_number')
  })

  it('shows the fallback copy for a line delivered by email', () => {
    s.data = ref(payload([line({ fallback: { used: true, email_state: 'sent' }, channel: 'email_fallback' })]))
    const wrapper = mountTab()

    expect(wrapper.text()).toContain('No llegó por WhatsApp — se envió por correo')
  })
})
