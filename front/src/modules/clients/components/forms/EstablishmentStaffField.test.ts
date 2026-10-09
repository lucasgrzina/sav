import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import EstablishmentStaffField from './EstablishmentStaffField.vue'
import { makeStaffMember } from '@/test/fixtures'

// The real select is an Ant Design internal; a stub exposes what the field passes down.
const BaseMultiSelectStub = defineComponent({
  name: 'BaseMultiSelect',
  props: ['modelValue', 'options', 'disabled'],
  emits: ['update:modelValue'],
  setup(props) {
    return () => h('div', { class: 'multi-select-stub', 'data-disabled': String(Boolean(props.disabled)) })
  },
})

const staff = [makeStaffMember('p1', 'Ana', 'client-owner'), makeStaffMember('p2', 'Beto', 'client-manager')]

function mountField(props: Record<string, unknown> = {}) {
  return mount(EstablishmentStaffField, {
    props: { staffOptions: staff, modelValue: [], ...props },
    global: { plugins: [Antd], stubs: { BaseMultiSelect: BaseMultiSelectStub } },
  })
}

describe('EstablishmentStaffField', () => {
  it('builds one option per client staff member with name and role label', () => {
    const wrapper = mountField()

    const options = wrapper.findComponent(BaseMultiSelectStub).props('options')
    expect(options).toEqual([
      { value: 'p1', label: 'Ana (Propietario)' },
      { value: 'p2', label: 'Beto (Receptor)' },
    ])
  })

  it('shows the selected values and emits model updates and change on selection', async () => {
    const wrapper = mountField({ modelValue: ['p1'] })
    const select = wrapper.findComponent(BaseMultiSelectStub)
    expect(select.props('modelValue')).toEqual(['p1'])

    await select.vm.$emit('update:modelValue', ['p1', 'p2'])

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['p1', 'p2']])
    expect(wrapper.emitted('change')).toHaveLength(1)
  })

  it('warns that nobody will be linked when the selection is empty', () => {
    const wrapper = mountField({ modelValue: [] })

    expect(wrapper.text()).toContain('Sin personal vinculado')
  })

  it('does not show the empty-selection warning when someone is selected', () => {
    const wrapper = mountField({ modelValue: ['p1'] })

    expect(wrapper.text()).not.toContain('Sin personal vinculado')
  })

  it('warns about unlinking when a previously linked member is removed', () => {
    const wrapper = mountField({ initialGuids: ['p1', 'p2'], modelValue: ['p1'] })

    expect(wrapper.text()).toContain('Se quitará como responsable de los programas activos')
  })

  it('does not warn about unlinking when the selection keeps every initial member', () => {
    const wrapper = mountField({ initialGuids: ['p1'], modelValue: ['p1', 'p2'] })

    expect(wrapper.text()).not.toContain('Se quitará como responsable')
  })

  it('disables the select and explains when the client has no staff', () => {
    const wrapper = mountField({ staffOptions: [] })

    expect(wrapper.findComponent(BaseMultiSelectStub).props('disabled')).toBe(true)
    expect(wrapper.text()).toContain('Este cliente todavía no tiene personal cargado')
  })

  it('shows the error message instead of the help text', () => {
    const wrapper = mountField({ error: 'Perfiles ajenos' })

    expect(wrapper.text()).toContain('Perfiles ajenos')
  })
})
