import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import type { Ref } from 'vue'
import { mount } from '@vue/test-utils'
import Antd from 'ant-design-vue'
import BaseDataTable from './BaseDataTable.vue'

const s = vi.hoisted(() => ({ isMobile: null as unknown as Ref<boolean> }))

vi.mock('@/core/composables/useResponsive', () => ({
  useResponsive: () => ({ isMobile: s.isMobile }),
}))

interface Row { guid: string, name: string, tax_id: string }

const rows: Row[] = [
  { guid: 'a', name: 'Estancia Uno', tax_id: '20-1' },
  { guid: 'b', name: 'Estancia Dos', tax_id: '20-2' },
]

const columns = [
  { title: 'Nombre', key: 'name' },
  { title: 'CUIT/Doc.', key: 'tax_id' },
  { title: 'Acciones', key: 'actions' },
]

function mountTable(extra: Record<string, unknown> = {}, onRow?: (r: Row) => void) {
  const Host = defineComponent({
    setup() {
      return () =>
        h(
          BaseDataTable,
          {
            columns,
            'data-source': rows,
            'row-key': 'guid',
            pagination: false,
            'mobile-cards': true,
            'mobile-title-key': 'name',
            'mobile-secondary-keys': ['tax_id'],
            ...(onRow ? { customRow: (r: Record<string, any>) => ({ onClick: () => onRow(r as Row) }) } : {}),
            ...extra,
          },
          {
            bodyCell: ({ column, record }: { column: { key: string }, record: Row }) =>
              column.key === 'actions'
                ? h('button', { class: 'row-action' }, 'Ver')
                : h('span', { class: `cell-${column.key}` }, record[column.key as keyof Row]),
          },
        )
    },
  })

  return mount(Host, { global: { plugins: [Antd] } })
}

describe('BaseDataTable mobile cards', () => {
  beforeEach(() => {
    s.isMobile = ref(true)
  })

  it('renders one card per row with title, secondary fields and actions on mobile', () => {
    const wrapper = mountTable()

    const cards = wrapper.findAll('[data-test="mobile-card"]')
    expect(cards).toHaveLength(2)
    expect(cards[0].text()).toContain('Estancia Uno')
    expect(cards[0].text()).toContain('CUIT/Doc.')
    expect(cards[0].text()).toContain('20-1')
    expect(cards[0].find('.row-action').exists()).toBe(true)
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('renders the regular table on desktop', () => {
    s.isMobile = ref(false)
    const wrapper = mountTable()

    expect(wrapper.find('[data-test="mobile-card"]').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(true)
  })

  it('stays a table on mobile when mobile-cards is not set', () => {
    const wrapper = mountTable({ 'mobile-cards': false })

    expect(wrapper.find('[data-test="mobile-card"]').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(true)
  })

  it('shows the empty state when there are no rows', () => {
    const wrapper = mountTable({ 'data-source': [] })

    expect(wrapper.find('[data-test="mobile-card"]').exists()).toBe(false)
    expect(wrapper.find('.base-table-empty').exists()).toBe(true)
  })

  it('keeps row click through customRow but not from the actions area', async () => {
    const onRow = vi.fn()
    const wrapper = mountTable({}, onRow)

    await wrapper.findAll('[data-test="mobile-card"]')[1].trigger('click')
    expect(onRow).toHaveBeenCalledWith(rows[1])

    onRow.mockClear()
    await wrapper.find('.row-action').trigger('click')
    expect(onRow).not.toHaveBeenCalled()
  })
})
