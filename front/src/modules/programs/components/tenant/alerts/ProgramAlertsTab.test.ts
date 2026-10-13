import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
  retry: vi.fn(),
  rebuild: vi.fn(),
  push: vi.fn(),
}))

vi.mock('vue-router', () => ({ useRouter: () => ({ push: s.push }) }))
vi.mock('../../../composables/useRetryProgramAlert', () => ({
  useRetryProgramAlert: () => ({ mutate: s.retry, isPending: ref(false) }),
}))
vi.mock('../../../composables/useRebuildProgramAlerts', () => ({
  useRebuildProgramAlerts: () => ({ mutate: s.rebuild, isPending: ref(false) }),
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

function payload(
  lines: ProgramAlertLine[],
  counters = { scheduled: 0, sent: 1, problem: 0, cancelled: 0, total: 1 },
  metaOverrides: Record<string, unknown> = {},
) {
  return {
    data: lines,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: lines.length,
    meta: {
      counters, warnings: [], timezone: 'America/Argentina/Buenos_Aires',
      out_of_sync: false, multi_group: false, program_cancelled_at: null, last_reconciled_at: null,
      ...metaOverrides,
    },
  }
}

function mountTab() {
  return mount(ProgramAlertsTab, {
    props: {
      vetGuid: 'vet-1',
      guid: 'prog-1',
      clientGuid: 'client-1',
      targets: [{ guid: 'g1', target_date: '2026-09-23', animals: [] }],
      managers: [{ guid: 'p1', name: 'Maria Perez', role: 'client-manager', origin: 'client' }],
      editable: true,
    },
    global: {
      plugins: [Antd, createTestingPinia({ stubActions: false, createSpy: vi.fn })],
      stubs: { PermissionGuard: { template: '<div><slot /></div>' } },
    },
    attachTo: document.body,
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
    s.retry.mockReset()
    s.rebuild.mockReset()
    s.push.mockReset()
  })

  afterEach(() => {
    document.body.innerHTML = ''
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

  describe('actions and banners', () => {
    const problemCounters = { scheduled: 0, sent: 0, problem: 1, cancelled: 0, total: 1 }
    const failedLine = (overrides: Partial<ProgramAlertLine> = {}) =>
      line({
        state: 'failed',
        bucket: 'problem',
        failure_code: 'no_whatsapp_contact',
        retry: { allowed: true, recipient_guid: 'rec-1', blocked_reason: null },
        ...overrides,
      })

    it('retries only after confirming the dialog', async () => {
      s.data = ref(payload([failedLine()], problemCounters))
      const wrapper = mountTab()

      await wrapper.get('[data-testid="alert-retry"]').trigger('click')
      await flushPromises()
      expect(s.retry).not.toHaveBeenCalled()

      const ok = document.body.querySelector('.ant-modal-footer .ant-btn-primary') as HTMLElement
      ok.click()
      await flushPromises()
      expect(s.retry).toHaveBeenCalledWith('rec-1')
    })

    it('disables the retry button and explains why when the server advice blocks it', () => {
      s.data = ref(payload([
        failedLine({ retry: { allowed: false, recipient_guid: 'rec-1', blocked_reason: 'already_delivered' } }),
      ], problemCounters))
      const wrapper = mountTab()

      expect(wrapper.get('[data-testid="alert-retry"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-testid="alert-retry-reason"]').text()).toBe('Ya llegó por correo')
    })

    it('does not offer retry when there is no advice or it is not retryable', () => {
      s.data = ref(payload([
        failedLine({ retry: { allowed: false, recipient_guid: 'rec-1', blocked_reason: 'not_retryable' } }),
        failedLine({ key: 'a2:p1', retry: null }),
      ], { ...problemCounters, total: 2, problem: 2 }))
      const wrapper = mountTab()

      expect(wrapper.find('[data-testid="alert-retry"]').exists()).toBe(false)
    })

    it('goes to the staff contacts of client recipients', async () => {
      s.data = ref(payload([failedLine()], problemCounters))
      const wrapper = mountTab()

      await wrapper.get('[data-testid="alert-go-contacts"]').trigger('click')
      expect(s.push).toHaveBeenCalledWith({
        name: 'clients-staff-edit',
        params: { vetGuid: 'vet-1', clientGuid: 'client-1', profileGuid: 'p1' },
      })
    })

    it('hides "Ir a contactos" for vet staff recipients', () => {
      s.data = ref(payload([failedLine({ recipient: { profile_guid: 'vet-p', name: 'Dr', role: 'vet' } })], problemCounters))
      const wrapper = mountTab()

      expect(wrapper.find('[data-testid="alert-go-contacts"]').exists()).toBe(false)
    })

    it('shows the out-of-sync banner and rebuilds on click', async () => {
      s.data = ref(payload([line()], undefined, { out_of_sync: true }))
      const wrapper = mountTab()

      expect(wrapper.get('[data-testid="out-of-sync-banner"]').text()).toContain(
        'Los avisos programados no coinciden con el plan actual',
      )
      await wrapper.get('[data-testid="rebuild-alerts"]').trigger('click')
      expect(s.rebuild).toHaveBeenCalledTimes(1)
    })

    it('summarizes only critical warnings in the banner and lists them on demand', async () => {
      s.data = ref(payload([line()], undefined, {
        warnings: [
          { code: 'RECIPIENT_WITHOUT_WHATSAPP', severity: 'critical', context: { name: 'Maria', count: 3 } },
          { code: 'RECIPIENT_EMAIL_ONLY', severity: 'warning', context: { name: 'Juan' } },
        ],
      }))
      const wrapper = mountTab()

      expect(wrapper.get('[data-testid="warnings-banner"]').text()).toContain(
        '3 avisos de este programa no se van a enviar.',
      )
      await wrapper.get('[data-testid="warnings-detail"]').trigger('click')
      await flushPromises()
      expect(document.body.textContent).toContain('Responsable sin WhatsApp')
    })

    it('shows no warning banner without critical warnings or for a cancelled program', () => {
      s.data = ref(payload([line()], undefined, {
        warnings: [{ code: 'RECIPIENT_EMAIL_ONLY', severity: 'warning', context: {} }],
      }))
      expect(mountTab().find('[data-testid="warnings-banner"]').exists()).toBe(false)

      s.data = ref(payload([line()], undefined, {
        out_of_sync: true,
        program_cancelled_at: '2026-10-12T10:00:00Z',
        warnings: [{ code: 'RECIPIENT_WITHOUT_WHATSAPP', severity: 'critical', context: {} }],
      }))
      const cancelled = mountTab()
      expect(cancelled.find('[data-testid="warnings-banner"]').exists()).toBe(false)
      expect(cancelled.find('[data-testid="out-of-sync-banner"]').exists()).toBe(false)
    })
  })
})
