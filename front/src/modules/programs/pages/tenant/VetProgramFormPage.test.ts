import { beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h, ref, toValue } from 'vue'
import type { Ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import VetProgramFormPage from './VetProgramFormPage.vue'

type ManagerOption = { guid: string; name: string; role: string }

const s = vi.hoisted(() => ({
  routeParams: {} as Record<string, string>,
  managerOptionsByEstablishment: null as unknown as Ref<Record<string, ManagerOption[] | undefined>>,
  fetching: null as unknown as Ref<boolean>,
  vetStaff: null as unknown as Ref<unknown[] | undefined>,
  vetStaffLoading: null as unknown as Ref<boolean>,
  programDetail: null as unknown as Ref<unknown>,
  managerOptionsCalls: [] as unknown[][],
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: s.routeParams }),
  useRouter: () => ({ push: vi.fn() }),
}))
vi.mock('@/modules/clients/composables/useClients', () => ({
  useClients: () => ({ data: ref({ data: [] }), isLoading: ref(false) }),
}))
vi.mock('@/modules/clients/composables/useClientEstablishments', () => ({
  useClientEstablishments: () => ({ data: ref([]), isLoading: ref(false) }),
}))
vi.mock('@/modules/vets/composables/useVetStaff', () => ({
  useVetStaff: () => ({ data: s.vetStaff, isLoading: s.vetStaffLoading }),
}))
vi.mock('@/modules/protocols/composables/useTechniqueTree', () => ({
  useTechniqueTree: () => ({ data: ref([]), isLoading: ref(false) }),
}))
vi.mock('@/modules/protocols/composables/useVetProtocolList', () => ({
  useVetProtocolList: () => ({ data: ref({ data: [] }), isLoading: ref(false) }),
}))
vi.mock('@/modules/protocols/composables/useVetProtocolDetail', () => ({
  useVetProtocolDetail: () => ({ data: ref(undefined) }),
}))
vi.mock('../../composables/useAnimalSearch', () => ({
  useAnimalSearch: () => ({ options: ref([]), loading: ref(false), search: vi.fn(), reset: vi.fn() }),
}))
vi.mock('../../composables/useProgramDetail', () => ({
  useProgramDetail: () => ({ data: s.programDetail, isLoading: ref(false) }),
}))
vi.mock('../../composables/useProgramMutations', () => ({
  useCreateProgram: () => ({ mutate: vi.fn(), isPending: ref(false), fieldErrors: ref(null) }),
  useUpdateProgram: () => ({ mutate: vi.fn(), isPending: ref(false), fieldErrors: ref(null) }),
}))
// Behaves like the real hook: options depend on the chosen establishment, and nothing
// is available while no establishment is chosen (disabled query).
vi.mock('../../composables/useClientManagerOptions', () => ({
  useClientManagerOptions: (clientId: unknown, establishmentId: unknown) => {
    s.managerOptionsCalls.push([clientId, establishmentId])
    return {
      data: computed(() => s.managerOptionsByEstablishment.value[toValue(establishmentId as string)]),
      isLoading: ref(false),
      isFetching: s.fetching,
    }
  },
}))

const ClientSectionStub = defineComponent({
  name: 'ProgramClientSection',
  props: ['clientId', 'establishmentId'],
  emits: ['update:clientId', 'update:establishmentId'],
  setup: () => () => h('div'),
})
const ManagersSectionStub = defineComponent({
  name: 'ProgramManagersSection',
  props: ['managerProfileIds', 'clientId', 'establishmentId', 'vetStaffOptions', 'clientStaffOptions'],
  emits: ['update:managerProfileIds'],
  setup: () => () => h('div'),
})
const emptyStub = (name: string) => defineComponent({ name, setup: () => () => h('div') })

const vetStaff = [
  { guid: 'vet-1', user: { name: 'Vet Uno' }, role: { name: 'vet' } },
  { guid: 'vet-2', user: { name: 'Vet Dos' }, role: { name: 'vet-assistant' } },
]
const ana = { guid: 'cli-ana', name: 'Ana', role: 'client-manager' }
const beto = { guid: 'cli-beto', name: 'Beto', role: 'client-manager' }
const carla = { guid: 'cli-carla', name: 'Carla', role: 'client-owner' }

function mountPage() {
  return mount(VetProgramFormPage, {
    global: {
      plugins: [Antd, createTestingPinia({ createSpy: vi.fn })],
      stubs: {
        ProgramClientSection: ClientSectionStub,
        ProgramManagersSection: ManagersSectionStub,
        ProgramTechniqueSection: emptyStub('ProgramTechniqueSection'),
        ProgramGroupsSection: emptyStub('ProgramGroupsSection'),
        ProgramOtherDataSection: emptyStub('ProgramOtherDataSection'),
        AppHeader: emptyStub('AppHeader'),
      },
    },
  })
}

type Wrapper = ReturnType<typeof mountPage>

const managersSection = (w: Wrapper) => w.findComponent(ManagersSectionStub)
const clientSection = (w: Wrapper) => w.findComponent(ClientSectionStub)
const selectedManagers = (w: Wrapper) => managersSection(w).props('managerProfileIds') as string[]
const clientStaffGuids = (w: Wrapper) =>
  (managersSection(w).props('clientStaffOptions') as { guid: string }[]).map((o) => o.guid)

async function pickClient(w: Wrapper, guid: string) {
  clientSection(w).vm.$emit('update:clientId', guid)
  await flushPromises()
}
async function pickEstablishment(w: Wrapper, guid: string) {
  clientSection(w).vm.$emit('update:establishmentId', guid)
  await flushPromises()
}
async function setManagers(w: Wrapper, guids: string[]) {
  managersSection(w).vm.$emit('update:managerProfileIds', guids)
  await flushPromises()
}

describe('VetProgramFormPage client managers', () => {
  beforeEach(() => {
    s.routeParams = { vetGuid: 'vet-tenant' }
    s.managerOptionsByEstablishment = ref({ 'est-1': [ana, beto], 'est-2': [beto, carla] })
    s.fetching = ref(false)
    s.vetStaff = ref(vetStaff)
    s.vetStaffLoading = ref(false)
    s.programDetail = ref(null)
    s.managerOptionsCalls = []
  })

  it('offers no client managers until an establishment is chosen', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')

    expect(clientStaffGuids(wrapper)).toEqual([])
  })

  it('offers the managers linked to the chosen establishment, mapped to guid/label/role', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')

    expect(managersSection(wrapper).props('clientStaffOptions')).toEqual([
      { guid: 'cli-ana', label: 'Ana', role: 'client-manager' },
      { guid: 'cli-beto', label: 'Beto', role: 'client-manager' },
    ])
  })

  it('passes the form client and establishment to the options composable', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')

    const [clientArg, establishmentArg] = s.managerOptionsCalls[0] as [Ref<string>, Ref<string>]
    expect(toValue(clientArg)).toBe('client-1')
    expect(toValue(establishmentArg)).toBe('est-1')
  })

  it('swaps the options when another establishment is chosen', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await pickEstablishment(wrapper, 'est-2')

    expect(clientStaffGuids(wrapper)).toEqual(['cli-beto', 'cli-carla'])
  })

  it('keeps vet managers and drops every client manager when the establishment changes', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await setManagers(wrapper, ['vet-1', 'cli-ana', 'cli-beto', 'vet-2'])

    await pickEstablishment(wrapper, 'est-2')

    expect(selectedManagers(wrapper)).toEqual(['vet-1', 'vet-2'])
  })

  it('keeps vet managers and drops client managers when the client changes, resetting the establishment', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await setManagers(wrapper, ['vet-1', 'cli-ana'])

    await pickClient(wrapper, 'client-2')

    expect(selectedManagers(wrapper)).toEqual(['vet-1'])
    expect(clientSection(wrapper).props('establishmentId')).toBe('')
  })

  it('prunes a selected client manager that is no longer linked once the options refresh', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await setManagers(wrapper, ['vet-1', 'cli-ana', 'cli-beto'])

    // e.g. staff was unlinked from the establishment elsewhere and the options were refetched
    s.managerOptionsByEstablishment.value = { ...s.managerOptionsByEstablishment.value, 'est-1': [beto] }
    await flushPromises()

    expect(selectedManagers(wrapper)).toEqual(['vet-1', 'cli-beto'])
  })

  it('does not prune while the options are being fetched', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await setManagers(wrapper, ['vet-1', 'cli-ana'])

    s.fetching.value = true
    s.managerOptionsByEstablishment.value = { ...s.managerOptionsByEstablishment.value, 'est-1': [beto] }
    await flushPromises()

    expect(selectedManagers(wrapper)).toEqual(['vet-1', 'cli-ana'])
  })

  it('does not prune client managers when the options of the establishment are unavailable (error)', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await setManagers(wrapper, ['vet-1', 'cli-ana'])

    s.managerOptionsByEstablishment.value = { ...s.managerOptionsByEstablishment.value, 'est-1': undefined }
    await flushPromises()

    expect(selectedManagers(wrapper)).toEqual(['vet-1', 'cli-ana'])
  })

  it('does not prune anything while the vet staff is still loading', async () => {
    const wrapper = mountPage()
    await pickClient(wrapper, 'client-1')
    await pickEstablishment(wrapper, 'est-1')
    await setManagers(wrapper, ['vet-1', 'cli-ana'])

    s.vetStaffLoading.value = true
    s.managerOptionsByEstablishment.value = { ...s.managerOptionsByEstablishment.value, 'est-1': [beto] }
    await flushPromises()

    expect(selectedManagers(wrapper)).toEqual(['vet-1', 'cli-ana'])
  })

  describe('edit mode', () => {
    it('hydrates managers from the saved program and prunes stale client managers', async () => {
      s.routeParams = { vetGuid: 'vet-tenant', guid: 'prog-1' }
      s.managerOptionsByEstablishment = ref({ 'est-1': [ana] })
      s.programDetail = ref({
        guid: 'prog-1',
        client: { guid: 'client-1', name: 'Cliente' },
        establishment: { guid: 'est-1', name: 'Campo' },
        technique: { guid: 'tech-1', name: 'Tecnica' },
        protocol: { guid: 'proto-1', name: 'Protocolo' },
        comments: null,
        targets: [],
        managers: [
          { guid: 'vet-1', name: 'Vet Uno', role: 'vet', origin: 'vet' },
          { guid: 'cli-ana', name: 'Ana', role: 'client-manager', origin: 'client' },
          { guid: 'cli-stale', name: 'Stale', role: 'client-manager', origin: 'client' },
        ],
      })

      const wrapper = mountPage()
      await flushPromises()

      expect(clientSection(wrapper).props('establishmentId')).toBe('est-1')
      expect(selectedManagers(wrapper)).toEqual(['vet-1', 'cli-ana'])
    })
  })
})
