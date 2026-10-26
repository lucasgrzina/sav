import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import ProgramPreviewPanel from './ProgramPreviewPanel.vue'
import type { ProgramPreview } from '../../../types/program-alerts.types'

const local = { iso: '', date: '2026-09-24', time: '09:00', weekday: 'jueves', weekday_short: 'jue', label: 'jueves 24/09/2026 a las 09:00' }

function makePreview(overrides: Partial<ProgramPreview> = {}): ProgramPreview {
  return {
    generated_at: '',
    timezone: 'America/Argentina/Buenos_Aires',
    truncated: false,
    template: { task_due_version: 2 },
    program: {
      company_name: null,
      client: { guid: 'c', name: 'Cliente SA' },
      establishment: { guid: 'e', name: 'Campo Norte' },
      protocol: { guid: 'p', name: 'IATF' },
      technique: { guid: 't', name: 'Bovinos' },
      managers: [{ guid: 'm', name: 'María Pérez', role: 'client-manager' }],
    },
    summary: { alerts_total: 2, alerts_will_send: 1, alerts_wont_send: 1, recipients_total: 1, groups_count: 1, window: { from: '2026-09-21', to: '2026-10-30' } },
    items: [{
      target_key: 'k1', target_guid: null, target_ref: '0', target_date: '2026-09-23', target_name: null,
      protocol_task_guid: null, task_description: 'Colecta de embriones', task_date: null, task_time: null,
      protocol_task_alert_guid: null, scheduled_at_local: local, scheduled_at_utc: '',
      will_send: true, skip_reason: null, roles: [], require_confirmation: false, free_text: null,
      recipients: [{
        profile_guid: 'r1', name: 'María Pérez', role: 'Encargado', channel: 'whatsapp',
        destination_masked: '+54 9 ••• •••• 4821', contact_status: 'ok', will_send: true, skip_reason: null,
        rendered_message: 'Hola María, texto exacto',
      }],
    }],
    warnings: [],
    diff: null,
    ...overrides,
  }
}

const mounted: { unmount: () => void }[] = []

afterEach(() => {
  mounted.splice(0).forEach((w) => w.unmount())
  document.body.innerHTML = ''
})

function mountPanel(props: Record<string, unknown> = {}) {
  const wrapper = mount(ProgramPreviewPanel, {
    attachTo: document.body,
    props: {
      open: true,
      acknowledged: false,
      status: 'ready',
      preview: makePreview(),
      requiresAcknowledgement: false,
      canConfirm: true,
      saving: false,
      ...props,
    },
    global: { plugins: [Antd] },
  })
  mounted.push(wrapper)
  return wrapper
}

const body = () => document.body

describe('ProgramPreviewPanel', () => {
  it('renders header, summary sentence and the masked destination', () => {
    mountPanel()
    const text = body().textContent ?? ''

    expect(text).toContain('Revisá los avisos')
    expect(text).toContain('Cliente SA')
    expect(text).toContain('Campo Norte')
    expect(text).toContain('Se programarán 2 avisos para 1 persona entre el 21/09/2026 y el 30/10/2026. El 1 no se enviará.')
  })

  it('titles each group with its name when set and with the date alone otherwise', () => {
    const base = makePreview().items[0]
    const items = [
      { ...base, target_key: 'k1', target_date: '2026-09-23', target_name: 'Lote Norte' },
      { ...base, target_key: 'k2', target_date: '2026-09-30', target_name: null },
    ]
    mountPanel({ preview: makePreview({ items }) })
    const text = body().textContent ?? ''

    expect(text).toContain('Grupo Lote Norte (23/09/2026) — 1 aviso')
    expect(text).toContain('Grupo del 30/09/2026 — 1 aviso')
  })

  it('does not say "para 0 personas" when nothing will be delivered', () => {
    const summary = { alerts_total: 7, alerts_will_send: 0, alerts_wont_send: 7, recipients_total: 0, groups_count: 1, window: { from: '2026-09-21', to: '2026-10-30' } }
    mountPanel({ preview: makePreview({ summary }) })
    const text = body().textContent ?? ''

    expect(text).toContain('Se calcularon 7 avisos, pero ninguno se enviará.')
    expect(text).not.toContain('0 personas')
  })

  it('renders one aggregated "Tareas sin alertas" row with the task names', () => {
    const warnings = [{
      code: 'TASK_WITHOUT_ALERTS' as const,
      severity: 'info' as const,
      context: { count: 12, items: [{ protocol_task_guid: 'a', description: 'Colecta' }, { protocol_task_guid: 'b', description: 'Transferencia' }] },
    }]
    mountPanel({ preview: makePreview({ warnings }) })
    const text = body().textContent ?? ''

    expect(text).toContain('Tareas sin alertas')
    expect(text).toContain('12 tareas del protocolo no tienen alertas configuradas: Colecta, Transferencia y 10 más.')
  })

  it('uses the singular copy for a single task without alerts', () => {
    const warnings = [{
      code: 'TASK_WITHOUT_ALERTS' as const,
      severity: 'info' as const,
      context: { count: 1, items: [{ protocol_task_guid: 'a', description: 'Colecta' }] },
    }]
    mountPanel({ preview: makePreview({ warnings }) })

    expect(body().textContent).toContain('1 tarea del protocolo no tiene alertas configuradas: Colecta.')
  })

  it('shows the acknowledgement checkbox only with critical warnings and disables confirm until allowed', () => {
    const critical = [{ code: 'ALERT_IN_PAST' as const, severity: 'critical' as const, context: {} }]
    mountPanel({ preview: makePreview({ warnings: critical }), requiresAcknowledgement: true, canConfirm: false })

    expect(body().querySelector('[data-testid="acknowledge"]')).not.toBeNull()
    expect(body().querySelector('[data-testid="confirm-save"]')?.hasAttribute('disabled')).toBe(true)
  })

  it('hides the checkbox without critical warnings and confirm emits', async () => {
    const wrapper = mountPanel()

    expect(body().querySelector('[data-testid="acknowledge"]')).toBeNull()
    ;(body().querySelector('[data-testid="confirm-save"]') as HTMLElement).click()
    expect(wrapper.emitted('confirm')).toHaveLength(1)
  })

  it('on error offers retry and save without summary', () => {
    const wrapper = mountPanel({ status: 'error', preview: null, canConfirm: false })

    expect(body().textContent).toContain('No pudimos generar el resumen')
    ;(body().querySelector('[data-testid="preview-retry"]') as HTMLElement).click()
    ;(body().querySelector('[data-testid="preview-save-without"]') as HTMLElement).click()
    expect(wrapper.emitted('retry')).toHaveLength(1)
    expect(wrapper.emitted('saveWithoutSummary')).toHaveLength(1)
  })

  it('renders the diff counts in edit mode', () => {
    const diff = { kept: 8, updated: 1, created: 4, cancelled: 2, rescheduled: [], cancelled_items: [], sent_not_modified: [], protocol_changed: false }
    mountPanel({ preview: makePreview({ diff }) })

    expect(body().querySelector('[data-testid="diff-counts"]')?.textContent).toBe(
      'Se conservan 8, se reprograman 1, se crean 4 y se cancelan 2.',
    )
  })
})
