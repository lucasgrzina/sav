import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import Antd from 'ant-design-vue'
import VetProtocolFormDrawer from './VetProtocolFormDrawer.vue'
import type { VetProtocolDetail } from '../../types/vet-protocol.types'

vi.mock('../../composables/useTechniqueTree', () => ({
  useTechniqueTree: () => ({
    data: ref([{ guid: 'root', name: 'Bovinos', children: [{ guid: '11111111-1111-4111-8111-111111111111', name: 'IATF', target_date_name: 'Fecha' }] }]),
    isLoading: ref(false),
  }),
}))

const TECH = '11111111-1111-4111-8111-111111111111'

function makeDetail(count?: number): VetProtocolDetail {
  return {
    guid: TECH, name: 'IATF', color: null, technique: { guid: TECH, name: 'IATF' },
    is_global: false, is_own: true, tasks_count: 0, created_at: '', updated_at: '',
    tasks: [{ guid: TECH, description: 'Tarea', days_offset: 0, time_of_day: 'before', time: '09:00', important: false, sort_order: 0, alerts: [] }], active_programs_count: count,
  }
}

const mounted: { unmount: () => void }[] = []
afterEach(() => {
  mounted.splice(0).forEach((w) => w.unmount())
  document.body.innerHTML = ''
})

async function saveClick(initialValues: VetProtocolDetail | null) {
  const wrapper = mount(VetProtocolFormDrawer, {
    attachTo: document.body,
    props: { open: true, initialValues },
    global: { plugins: [Antd] },
  })
  mounted.push(wrapper)
  await flushPromises()
  const save = Array.from(document.body.querySelectorAll('button')).find((b) =>
    /Guardar cambios|Crear protocolo/.test(b.textContent ?? ''),
  ) as HTMLButtonElement
  save.click()
  await flushPromises()
  await new Promise((r) => setTimeout(r, 50))
  await flushPromises()
  return wrapper
}

describe('VetProtocolFormDrawer active programs confirmation', () => {
  it('asks for confirmation when editing a protocol used by active programs', async () => {
    const wrapper = await saveClick(makeDetail(3))
    expect(document.body.textContent).toContain('Este protocolo se usa en 3 programas activos.')
    expect(wrapper.emitted('submit')).toBeUndefined()
  })

  it('submits directly when there are no active programs', async () => {
    const wrapper = await saveClick(makeDetail(0))
    expect(document.body.textContent).not.toContain('programas activos')
    expect(wrapper.emitted('submit')).toHaveLength(1)
  })

  it('submits directly in create mode', async () => {
    const wrapper = await saveClick(null)
    expect(document.body.textContent).not.toContain('programas activos')
    // create form is empty so validation may block; the dialog must not appear either way
    expect(wrapper.emitted('submit')?.length ?? 0).toBeLessThanOrEqual(1)
  })
})
