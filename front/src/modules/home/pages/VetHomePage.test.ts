import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import type { Ref } from 'vue'
import { mount, RouterLinkStub } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import VetHomePage from './VetHomePage.vue'
import type { HomeProgramSummary, VetHomeData } from '../types/home.types'

const s = vi.hoisted(() => ({
  permissions: [] as string[],
  data: null as unknown as Ref<VetHomeData | undefined>,
  isLoading: null as unknown as Ref<boolean>,
  error: null as unknown as Ref<Error | null>,
  refetch: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { vetGuid: 'vet-1' } }),
}))
vi.mock('@/core/composables/usePermissions', () => ({
  usePermission: () => ({ can: (p: string) => s.permissions.includes(p) }),
}))
vi.mock('@/composables/useAuth', () => ({
  useAuth: () => ({ user: ref({ first_name: 'Lucía' }) }),
}))
vi.mock('../composables/useVetHome', () => ({
  useVetHome: () => ({ data: s.data, isLoading: s.isLoading, isFetching: ref(false), error: s.error, refetch: s.refetch }),
}))

const program: HomeProgramSummary = {
  guid: 'prog-1',
  client: { guid: 'c-1', name: 'Cliente Uno' },
  establishment: { guid: 'e-1', name: 'Estancia Norte' },
  technique: { guid: 't-1', name: 'IATF' },
  protocol: { guid: 'p-1', name: 'Protocolo A' },
}

const local = { iso: '', date: '2030-05-31', time: '08:30', weekday: 'viernes', weekday_short: 'vie', label: 'vie 31/05/2030 08:30' }

function build(overrides: Partial<VetHomeData> = {}): VetHomeData {
  return {
    generated_at: '2030-06-01T10:00:00Z',
    timezone: 'America/Argentina/Buenos_Aires',
    upcoming_programs: [{ ...program, next_target_date: '2030-06-05' }],
    recent_alerts: [{
      key: 'a1:u1', type: 'program.task_due', state: 'delivered',
      recipient: { profile_guid: 'u1', name: 'María Pérez', role: 'client-owner' },
      task_description: 'Colecta de embriones', scheduled_at_local: local, sent_at: '2030-05-31T11:30:05Z',
      sent_at_local: local, destination_masked: '+54 ••• •••• 4455', program,
    }],
    problem_alerts: [{
      key: 'a2:u2', type: 'program.task_due', state: 'not_sent', failure_code: 'no_whatsapp_contact',
      recipient: { profile_guid: 'u2', name: 'Pedro Gómez', role: 'client-manager' },
      task_description: null, scheduled_at_local: local, sent_at: null, sent_at_local: null,
      destination_masked: null, program,
    }],
    has_more: { upcoming_programs: false, recent_alerts: false, problem_alerts: false },
    ...overrides,
  }
}

const stub = (name: string) => defineComponent({ name, setup: (_, { slots }) => () => h('div', { 'data-stub': name }, slots.default?.()) })

function mountPage() {
  return mount(VetHomePage, {
    global: {
      plugins: [Antd, createTestingPinia({ createSpy: vi.fn })],
      stubs: { AppHeader: stub('AppHeader'), RouterLink: RouterLinkStub },
    },
  })
}

describe('VetHomePage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    s.permissions = ['programs.read', 'programs.alerts.read']
    s.data = ref(build())
    s.isLoading = ref(false)
    s.error = ref(null)
  })

  it('renders the three blocks with the program link, readable reason and masked destination', () => {
    const wrapper = mountPage()

    const upcoming = wrapper.get('[data-testid="upcoming-item"]')
    expect(upcoming.text()).toContain('Cliente Uno')
    expect(upcoming.text()).toContain('Estancia Norte')
    expect(upcoming.text()).toContain('Protocolo A')
    expect(upcoming.text()).toContain('IATF')
    expect(upcoming.getComponent(RouterLinkStub).props('to')).toEqual({
      name: 'vet-programs-detail', params: { vetGuid: 'vet-1', guid: 'prog-1' },
    })

    const recent = wrapper.get('[data-testid="recent-item"]')
    expect(recent.text()).toContain('María Pérez')
    expect(recent.text()).toContain('Colecta de embriones')
    expect(recent.text()).toContain('vie 31/05/2030 08:30')

    const problem = wrapper.get('[data-testid="problem-item"]')
    expect(problem.text()).toContain('Pedro Gómez')
    expect(problem.text()).toContain('Recordatorio de tarea')
    expect(wrapper.get('[data-testid="problem-reason"]').text()).toContain('No tiene un WhatsApp cargado')
    expect(problem.getComponent(RouterLinkStub).props('to')).toMatchObject({
      name: 'vet-programs-detail', params: { guid: 'prog-1' }, query: { tab: 'alerts' },
    })
    expect(wrapper.text()).not.toContain('5491122334455')
  })

  it('shows no "more" note when no list was cut', () => {
    const wrapper = mountPage()

    expect(wrapper.find('[data-testid="section-more"]').exists()).toBe(false)
  })

  it('shows a "more" note with a link to the programs list on each cut block', () => {
    s.data = ref(build({ has_more: { upcoming_programs: true, recent_alerts: true, problem_alerts: true } }))
    const wrapper = mountPage()

    expect(wrapper.get('[data-testid="home-upcoming"] [data-testid="section-more"]').text()).toContain('Hay más programas')
    expect(wrapper.get('[data-testid="home-recent"] [data-testid="section-more"]').text()).toContain('Hay más avisos. Mostramos los más recientes.')
    const problems = wrapper.get('[data-testid="home-problems"] [data-testid="section-more"]')
    expect(problems.text()).toContain('Hay más avisos con problemas')
    expect(problems.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'vet-programs-list', params: { vetGuid: 'vet-1' } })
  })

  it('flags only the block that was cut', () => {
    s.data = ref(build({ has_more: { upcoming_programs: false, recent_alerts: false, problem_alerts: true } }))
    const wrapper = mountPage()

    expect(wrapper.find('[data-testid="home-problems"] [data-testid="section-more"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="home-upcoming"] [data-testid="section-more"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="home-recent"] [data-testid="section-more"]').exists()).toBe(false)
  })

  it('shows friendly empty states for each block', () => {
    s.data = ref(build({ upcoming_programs: [], recent_alerts: [], problem_alerts: [] }))
    const wrapper = mountPage()

    expect(wrapper.get('[data-testid="home-upcoming"]').text()).toContain('No hay programas con fecha próxima.')
    expect(wrapper.get('[data-testid="home-recent"]').text()).toContain('Todavía no se enviaron avisos.')
    expect(wrapper.get('[data-testid="home-problems"]').text()).toContain('No hay avisos con problemas')
  })

  it('hides the alert blocks when the backend returns them as null (no programs.alerts.read)', () => {
    s.permissions = ['programs.read']
    s.data = ref(build({ recent_alerts: null, problem_alerts: null }))
    const wrapper = mountPage()

    expect(wrapper.find('[data-testid="home-upcoming"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="home-recent"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="home-problems"]').exists()).toBe(false)
  })

  it('shows an error state with retry', async () => {
    s.data = ref(undefined)
    s.error = ref(new Error('boom'))
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('No pudimos cargar tu resumen')
    await wrapper.get('button.ant-btn-primary').trigger('click')
    expect(s.refetch).toHaveBeenCalled()
  })

  it('shows a loading skeleton and no blocks while loading', () => {
    s.data = ref(undefined)
    s.isLoading = ref(true)
    const wrapper = mountPage()

    expect(wrapper.find('.ant-skeleton').exists()).toBe(true)
    expect(wrapper.find('[data-testid="home-upcoming"]').exists()).toBe(false)
  })

  it('explains the missing access instead of requesting data without programs.read', () => {
    s.permissions = []
    const wrapper = mountPage()

    expect(wrapper.find('[data-testid="home-no-access"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="home-upcoming"]').exists()).toBe(false)
  })
})
