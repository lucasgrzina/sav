import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import ProgramTargetsTimeline from './ProgramTargetsTimeline.vue'
import type { ProgramProjectedAlert, ProgramTarget } from '../../types/program.types'

function target(alert: Partial<ProgramProjectedAlert>): ProgramTarget {
  return {
    guid: 'g1',
    target_date: '2026-09-23',
    animals: [],
    tasks: [{
      protocol_task_guid: 't1',
      description: 'Colecta',
      occurs_on: '2026-09-24',
      occurs_at: '09:00',
      important: false,
      alerts: [{
        protocol_task_alert_guid: 'a1',
        occurs_on: '2026-09-24',
        occurs_at: '09:00',
        roles: ['vet'],
        message: 'msg',
        require_confirmation: false,
        recipients: [{ guid: 'p1', name: 'Maria', role: 'vet' }],
        ...alert,
      }],
    }],
  }
}

async function mountOpen(alert: Partial<ProgramProjectedAlert>) {
  const wrapper = mount(ProgramTargetsTimeline, {
    props: { targets: [target(alert)] },
    global: { plugins: [Antd] },
  })
  await wrapper.get('.ant-collapse-header').trigger('click')
  return wrapper
}

describe('ProgramTargetsTimeline', () => {
  it('shows the local label and the "Ya pasó" tag for past alerts', async () => {
    const wrapper = await mountOpen({
      timing: 'past',
      scheduled_at_local: {
        iso: '', date: '', time: '', weekday: '', weekday_short: 'jue', label: 'jue 24/09/2026 09:00',
      },
    })

    expect(wrapper.text()).toContain('jue 24/09/2026 09:00')
    expect(wrapper.get('[data-testid="ptt-past-tag"]').text()).toBe('Ya pasó')
  })

  it('does not tag future alerts', async () => {
    const wrapper = await mountOpen({ timing: 'future' })

    expect(wrapper.find('[data-testid="ptt-past-tag"]').exists()).toBe(false)
  })

  it('shows "Sin destinatarios para este rol" when skip_reason is no_recipients', async () => {
    const wrapper = await mountOpen({ recipients: [], skip_reason: 'no_recipients' })

    expect(wrapper.get('[data-testid="ptt-no-recipients"]').text()).toBe('Sin destinatarios para este rol.')
  })
})
