import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import EstablishmentsSection from './EstablishmentsSection.vue'
import { makeEstablishment, makeStaffMember } from '@/test/fixtures'
import type { EstablishmentItem } from '../types/client.types'

const state = vi.hoisted(() => ({
  tenantData: null as unknown,
  adminData: null as unknown,
}))

vi.mock('../composables/useClientEstablishments', () => ({
  useClientEstablishments: () => ({ data: state.tenantData, isLoading: ref(false) }),
}))
vi.mock('../composables/useDeleteEstablishment', () => ({
  useDeleteEstablishment: () => ({ deleteEstablishment: vi.fn(), isPending: ref(false) }),
}))
vi.mock('../composables/admin/useAdminClientEstablishments', () => ({
  useAdminClientEstablishments: () => ({ data: state.adminData, isLoading: ref(false) }),
}))
vi.mock('../composables/admin/useAdminDeleteEstablishment', () => ({
  useAdminDeleteEstablishment: () => ({ deleteEstablishment: vi.fn(), isPending: ref(false) }),
}))

const ModalStub = (name: string) => defineComponent({ name, setup: () => () => h('div') })

function mountSection(establishments: EstablishmentItem[], mode: 'tenant' | 'admin' = 'tenant') {
  state.tenantData = ref(establishments)
  state.adminData = ref(establishments)
  return mount(EstablishmentsSection, {
    props: { clientGuid: 'client-1', mode },
    global: {
      plugins: [
        Antd,
        createTestingPinia({
          createSpy: vi.fn,
          initialState: { auth: { user: { permissions: ['establishments.update'], roles: [] }, tenantPermissions: [] } },
        }),
      ],
      stubs: {
        EstablishmentFormModal: ModalStub('EstablishmentFormModal'),
        AdminEstablishmentFormModal: ModalStub('AdminEstablishmentFormModal'),
      },
    },
  })
}

describe('EstablishmentsSection staff column', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the "Sin personal vinculado" badge when staff_count is 0', () => {
    const wrapper = mountSection([makeEstablishment({ staff_count: 0 })])

    expect(wrapper.text()).toContain('Sin personal vinculado')
  })

  it('shows the badge when neither staff nor staff_count are present', () => {
    const wrapper = mountSection([makeEstablishment()])

    expect(wrapper.text()).toContain('Sin personal vinculado')
  })

  it('shows the linked staff as chips and no badge when staff is present', () => {
    const wrapper = mountSection([
      makeEstablishment({ staff: [makeStaffMember('p1', 'Ana', 'client-owner')], staff_count: 1 }),
    ])

    expect(wrapper.text()).toContain('Ana · Propietario')
    expect(wrapper.text()).not.toContain('Sin personal vinculado')
  })

  it('shows neither chips nor badge when staff_count > 0 but the staff list is hidden (no clients.staff.read)', () => {
    const wrapper = mountSection([makeEstablishment({ staff_count: 2 })])

    expect(wrapper.text()).not.toContain('Sin personal vinculado')
  })

  it('shows the badge only for establishments without staff in a mixed list', () => {
    const wrapper = mountSection([
      makeEstablishment({ guid: 'e1', name: 'Con personal', staff: [makeStaffMember('p1', 'Ana')], staff_count: 1 }),
      makeEstablishment({ guid: 'e2', name: 'Sin nadie', staff_count: 0 }),
    ])

    expect(wrapper.text().match(/Sin personal vinculado/g)).toHaveLength(1)
  })

  it('renders the badge in admin mode too', () => {
    const wrapper = mountSection([makeEstablishment({ staff_count: 0 })], 'admin')

    expect(wrapper.text()).toContain('Sin personal vinculado')
  })

  it('shows the empty state instead of the table when there are no establishments', () => {
    const wrapper = mountSection([])

    expect(wrapper.text()).toContain('Todavía no cargaste establecimientos')
  })
})

describe('EstablishmentsSection coordinates column', () => {
  it('shows latitude and longitude with 6 decimals', () => {
    const wrapper = mountSection([makeEstablishment({ latitude: -35.1234567, longitude: -62.5 })])

    expect(wrapper.get('[data-testid="establishment-coordinates"]').text()).toBe('-35.123457, -62.500000')
  })

  it('shows "Sin coordenadas" when they are null', () => {
    const wrapper = mountSection([makeEstablishment({ latitude: null, longitude: null })])

    expect(wrapper.text()).toContain('Sin coordenadas')
    expect(wrapper.find('[data-testid="establishment-coordinates"]').exists()).toBe(false)
  })
})
