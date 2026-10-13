import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import type { Ref } from 'vue'
import { mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import VetProgramDetailPage from './VetProgramDetailPage.vue'

const s = vi.hoisted(() => ({
  query: {} as Record<string, string>,
  permissions: [] as string[],
  problem: null as unknown as Ref<number>,
  program: null as unknown as Ref<unknown>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { vetGuid: 'vet-1', guid: 'prog-1' }, query: s.query }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))
vi.mock('@/core/composables/usePermissions', () => ({
  usePermission: () => ({ can: (p: string) => s.permissions.includes(p) }),
}))
vi.mock('../../composables/useProgramDetail', () => ({
  useProgramDetail: () => ({ data: s.program, isLoading: ref(false) }),
}))
vi.mock('../../composables/useProgramAlerts', () => ({
  PROGRAM_ALERTS_PER_PAGE: 25,
  useProgramAlerts: () => ({
    data: ref({ data: [], meta: { counters: { problem: s.problem.value } } }),
    isError: ref(false), isLoading: ref(false), isFetching: ref(false), refetch: vi.fn(),
  }),
}))
vi.mock('../../composables/useProgramMutations', () => ({
  useCancelProgramWithModal: () => ({
    selectedProgram: ref(null), showCancelModal: ref(false), isPending: ref(false),
    openCancelModal: vi.fn(), closeCancelModal: vi.fn(), confirmCancel: vi.fn(),
  }),
}))

const stub = (name: string) => defineComponent({ name, setup: () => () => h('div', { 'data-stub': name }) })

function mountPage() {
  return mount(VetProgramDetailPage, {
    global: {
      plugins: [Antd, createTestingPinia({ createSpy: vi.fn })],
      stubs: {
        AppHeader: stub('AppHeader'),
        ProgramInfoCards: stub('ProgramInfoCards'),
        ProgramTargetsTimeline: stub('ProgramTargetsTimeline'),
        ProgramCancelModal: stub('ProgramCancelModal'),
        ProgramAlertsTab: stub('ProgramAlertsTab'),
        PermissionGuard: { template: '<div><slot /></div>' },
      },
    },
  })
}

describe('VetProgramDetailPage alerts tab', () => {
  beforeEach(() => {
    s.query = {}
    s.permissions = ['programs.read', 'programs.alerts.read']
    s.problem = ref(0)
    s.program = ref({
      guid: 'prog-1',
      client: { guid: 'c', name: 'Cliente' },
      establishment: { guid: 'e', name: 'Campo' },
      cancelled_at: null,
      editable: true,
      targets: [],
      managers: [],
    })
  })

  it('mounts the Avisos tab when the user has programs.alerts.read', () => {
    const wrapper = mountPage()
    expect(wrapper.text()).toContain('Avisos')
    expect(wrapper.text()).toContain('Cronograma')
  })

  it('does not render the Avisos tab without programs.alerts.read', () => {
    s.permissions = ['programs.read']
    s.query = { tab: 'alerts' }
    const wrapper = mountPage()

    expect(wrapper.text()).not.toContain('Avisos')
    expect(wrapper.find('[data-stub="ProgramAlertsTab"]').exists()).toBe(false)
  })

  it('shows the red dot only when there are problems', () => {
    expect(mountPage().find('[data-testid="alerts-tab-badge"] .ant-badge-dot').exists()).toBe(false)

    s.problem = ref(2)
    expect(mountPage().find('[data-testid="alerts-tab-badge"] .ant-badge-dot').exists()).toBe(true)
  })

  it('shows the cancelled banner with the cancellation date', () => {
    s.program = ref({
      ...(s.program.value as object),
      cancelled_at: '2026-10-12T10:00:00-03:00',
      editable: false,
    })
    const wrapper = mountPage()

    expect(wrapper.get('[data-testid="program-cancelled-banner"]').text()).toContain('Programa cancelado el 12/10/2026')
  })
})
