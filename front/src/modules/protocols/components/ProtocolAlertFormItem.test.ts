import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import ProtocolAlertFormItem from './ProtocolAlertFormItem.vue'
import type { ProtocolTaskAlertFormValues } from '../validators/protocol.validator'

function mountItem(message: string) {
  const alert: ProtocolTaskAlertFormValues = {
    offset_days: 1,
    time_of_day: 'before',
    time: '09:00',
    roles: ['vet'],
    message,
    require_confirmation: false,
  }
  return mount(ProtocolAlertFormItem, {
    props: { alert, index: 0, isFirst: true, isLast: true },
    global: { plugins: [Antd] },
  })
}

describe('ProtocolAlertFormItem message counter', () => {
  it('shows the length without warning under the limit', () => {
    const counter = mountItem('a'.repeat(200)).get('[data-testid="alert-message-counter"]')
    expect(counter.text()).toContain('200/200')
    expect(counter.classes()).not.toContain('pafi-counter--over')
  })

  it('shows a soft warning above 200 chars and does not block editing', () => {
    const wrapper = mountItem('a'.repeat(250))
    const counter = wrapper.get('[data-testid="alert-message-counter"]')
    expect(counter.text()).toContain('250/200')
    expect(counter.classes()).toContain('pafi-counter--over')
    expect(wrapper.find('textarea').attributes('maxlength')).toBeUndefined()
  })
})
