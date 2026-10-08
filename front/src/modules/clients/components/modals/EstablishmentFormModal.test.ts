import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import Antd from 'ant-design-vue'
import EstablishmentFormModal from './EstablishmentFormModal.vue'
import { makeEstablishment, makeStaffMember } from '@/test/fixtures'

const h_ = vi.hoisted(() => ({
  create: {} as Record<string, unknown>,
  update: {} as Record<string, unknown>,
  sync: {} as Record<string, unknown>,
  staff: {} as Record<string, unknown>,
}))

vi.mock('../../composables/useCreateEstablishment', () => ({ useCreateEstablishment: () => h_.create }))
vi.mock('../../composables/useUpdateEstablishment', () => ({ useUpdateEstablishment: () => h_.update }))
vi.mock('../../composables/useSyncEstablishmentStaff', () => ({ useSyncEstablishmentStaff: () => h_.sync }))
vi.mock('../../composables/useClientStaff', () => ({ useClientStaff: () => h_.staff }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { vetGuid: 'vet-1' } }) }))

const BaseMultiSelectStub = defineComponent({
  name: 'BaseMultiSelect',
  props: ['modelValue', 'options'],
  emits: ['update:modelValue'],
  setup: () => () => h('div'),
})

const BaseModalStub = defineComponent({
  name: 'BaseModal',
  props: ['modelValue', 'title'],
  setup: (_, { slots }) => () => h('div', [slots.default?.(), slots.footer?.()]),
})

const staff = [makeStaffMember('p1', 'Ana'), makeStaffMember('p2', 'Beto'), makeStaffMember('p3', 'Carla')]

function mountModal(options: { mode: 'create' | 'edit'; initial?: Parameters<typeof makeEstablishment>[0]; permissions?: string[] }) {
  const permissions = options.permissions ?? ['establishments.update']
  return mount(EstablishmentFormModal, {
    props: {
      clientGuid: 'client-1',
      mode: options.mode,
      modelValue: true,
      initial: options.mode === 'edit' ? makeEstablishment(options.initial) : undefined,
    },
    global: {
      plugins: [
        Antd,
        createTestingPinia({
          createSpy: vi.fn,
          initialState: { auth: { user: { permissions, roles: [] }, tenantPermissions: [] } },
        }),
      ],
      stubs: { BaseModal: BaseModalStub, BaseMultiSelect: BaseMultiSelectStub },
    },
  })
}

async function clickButton(wrapper: ReturnType<typeof mountModal>, text: string) {
  const button = wrapper.findAll('button').find((b) => b.text().includes(text))
  expect(button, `button "${text}"`).toBeTruthy()
  await flushPromises()
  await button!.trigger('click')
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 20))
  await flushPromises()
}

describe('EstablishmentFormModal', () => {
  beforeEach(() => {
    h_.create = {
      mutateAsync: vi.fn().mockResolvedValue({ guid: 'new-est' }),
      isPending: ref(false),
      fieldErrors: ref(null),
    }
    h_.update = {
      mutateAsync: vi.fn().mockResolvedValue({}),
      isPending: ref(false),
      fieldErrors: ref(null),
    }
    h_.sync = {
      mutateAsync: vi.fn().mockResolvedValue({}),
      isPending: ref(false),
      generalError: ref(null),
      resetErrors: vi.fn(),
    }
    h_.staff = { data: ref(staff), isLoading: ref(false) }
  })

  describe('edit mode', () => {
    const initial = {
      guid: 'est-9',
      name: 'Campo Norte',
      staff: [makeStaffMember('p1', 'Ana'), makeStaffMember('p2', 'Beto')],
    }

    it('preloads the linked staff of the establishment in the selector', () => {
      const wrapper = mountModal({ mode: 'edit', initial })

      expect(wrapper.findComponent(BaseMultiSelectStub).props('modelValue')).toEqual(['p1', 'p2'])
    })

    it('offers every staff member of the client as an option', () => {
      const wrapper = mountModal({ mode: 'edit', initial })

      const values = (wrapper.findComponent(BaseMultiSelectStub).props('options') as { value: string }[]).map((o) => o.value)
      expect(values).toEqual(['p1', 'p2', 'p3'])
    })

    it('sends user_profile_guids with the new selection after updating the establishment', async () => {
      const wrapper = mountModal({ mode: 'edit', initial })

      await wrapper.findComponent(BaseMultiSelectStub).vm.$emit('update:modelValue', ['p2', 'p3'])
      await clickButton(wrapper, 'Guardar cambios')

      expect(h_.update.mutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({ clientGuid: 'client-1', estGuid: 'est-9' }),
      )
      expect(h_.sync.mutateAsync).toHaveBeenCalledWith({
        clientGuid: 'client-1',
        estGuid: 'est-9',
        payload: { user_profile_guids: ['p2', 'p3'] },
      })
      expect(wrapper.emitted('success')).toHaveLength(1)
    })

    it('allows saving with an empty selection (unlinks everyone)', async () => {
      const wrapper = mountModal({ mode: 'edit', initial })

      await wrapper.findComponent(BaseMultiSelectStub).vm.$emit('update:modelValue', [])
      await clickButton(wrapper, 'Guardar cambios')

      expect(h_.sync.mutateAsync).toHaveBeenCalledWith({
        clientGuid: 'client-1',
        estGuid: 'est-9',
        payload: { user_profile_guids: [] },
      })
    })

    it('does not call the sync endpoint when the selection did not change', async () => {
      const wrapper = mountModal({ mode: 'edit', initial })

      await clickButton(wrapper, 'Guardar cambios')

      expect(h_.update.mutateAsync).toHaveBeenCalledTimes(1)
      expect(h_.sync.mutateAsync).not.toHaveBeenCalled()
      expect(wrapper.emitted('success')).toHaveLength(1)
    })

    it('keeps the modal open and does not emit success when the staff sync fails', async () => {
      ;(h_.sync.mutateAsync as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('fail'))
      const wrapper = mountModal({ mode: 'edit', initial })

      await wrapper.findComponent(BaseMultiSelectStub).vm.$emit('update:modelValue', ['p1'])
      await clickButton(wrapper, 'Guardar cambios')

      expect(wrapper.emitted('success')).toBeUndefined()
      expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    it('does not sync staff when updating the establishment fails', async () => {
      ;(h_.update.mutateAsync as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('fail'))
      const wrapper = mountModal({ mode: 'edit', initial })

      await wrapper.findComponent(BaseMultiSelectStub).vm.$emit('update:modelValue', ['p1'])
      await clickButton(wrapper, 'Guardar cambios')

      expect(h_.sync.mutateAsync).not.toHaveBeenCalled()
    })

    it('hides the staff selector and never syncs without establishments.update', async () => {
      const wrapper = mountModal({ mode: 'edit', initial, permissions: ['establishments.create'] })

      expect(wrapper.findComponent(BaseMultiSelectStub).exists()).toBe(false)
      await clickButton(wrapper, 'Guardar cambios')

      expect(h_.update.mutateAsync).toHaveBeenCalledTimes(1)
      expect(h_.sync.mutateAsync).not.toHaveBeenCalled()
    })
  })

  describe('create mode', () => {
    it('starts with an empty staff selection', () => {
      const wrapper = mountModal({ mode: 'create' })

      expect(wrapper.findComponent(BaseMultiSelectStub).props('modelValue')).toEqual([])
    })

    it('shows validation errors and does not call the API when the name is empty', async () => {
      const wrapper = mountModal({ mode: 'create' })

      await clickButton(wrapper, 'Crear establecimiento')

      expect(wrapper.find('.ant-form-item-has-error').exists()).toBe(true)
      expect(h_.create.mutateAsync).not.toHaveBeenCalled()
    })

    it('creates the establishment and then syncs the chosen staff using the new guid', async () => {
      const wrapper = mountModal({ mode: 'create' })

      await wrapper.find('input').setValue('Campo Nuevo')
      await wrapper.findComponent(BaseMultiSelectStub).vm.$emit('update:modelValue', ['p1', 'p3'])
      await clickButton(wrapper, 'Crear establecimiento')

      expect(h_.create.mutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({ clientGuid: 'client-1', payload: expect.objectContaining({ name: 'Campo Nuevo' }) }),
      )
      expect(h_.sync.mutateAsync).toHaveBeenCalledWith({
        clientGuid: 'client-1',
        estGuid: 'new-est',
        payload: { user_profile_guids: ['p1', 'p3'] },
      })
      expect(wrapper.emitted('success')).toHaveLength(1)
    })

    it('creates without syncing when no staff is selected', async () => {
      const wrapper = mountModal({ mode: 'create' })

      await wrapper.find('input').setValue('Campo Nuevo')
      await clickButton(wrapper, 'Crear establecimiento')

      expect(h_.create.mutateAsync).toHaveBeenCalledTimes(1)
      expect(h_.sync.mutateAsync).not.toHaveBeenCalled()
      expect(wrapper.emitted('success')).toHaveLength(1)
    })
  })
})
