import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useProgramSaveFlow } from './useProgramSaveFlow'
import type { CreateProgramPayload } from '../types/program.types'
import type { ProgramPreview, ProgramWarning } from '../types/program-alerts.types'

const m = vi.hoisted(() => ({
  push: vi.fn(),
  warning: vi.fn(),
  previewAsync: vi.fn(),
  createMutate: vi.fn(),
  updateMutate: vi.fn(),
}))

vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }) }))
vi.mock('@/core/composables/useNotification', () => ({ useNotification: () => ({ warning: m.warning }) }))
vi.mock('./useProgramPreview', () => ({ useProgramPreview: () => ({ mutateAsync: m.previewAsync }) }))
vi.mock('./useProgramMutations', () => ({
  useCreateProgram: () => ({ mutate: m.createMutate, isPending: ref(false), fieldErrors: ref(null) }),
  useUpdateProgram: () => ({ mutate: m.updateMutate, isPending: ref(false), fieldErrors: ref(null) }),
}))

const payload: CreateProgramPayload = {
  client_id: 'c1',
  establishment_id: 'e1',
  protocol_id: 'p1',
  comments: null,
  targets: [
    { guid: 'g1', target_date: '2026-09-23', name: null, animals: [{ rp: '1' }] },
    { target_date: '2026-09-30', animals: [] },
  ],
  manager_profile_ids: ['m1'],
}

function previewWith(warnings: ProgramWarning[]): ProgramPreview {
  return { warnings, items: [], diff: null } as unknown as ProgramPreview
}

const critical: ProgramWarning = { code: 'ALERT_IN_PAST', severity: 'critical', context: {} }
const info: ProgramWarning = { code: 'RECIPIENT_EMAIL_ONLY', severity: 'info', context: {} }

function setup(editingGuid: string | null = null) {
  const onSaved = vi.fn()
  const flow = useProgramSaveFlow('vet-1', editingGuid, onSaved)
  return { flow, onSaved }
}

describe('useProgramSaveFlow', () => {
  beforeEach(() => vi.clearAllMocks())

  it('start() previews without creating or updating, and strips animals from the payload', async () => {
    m.previewAsync.mockResolvedValue(previewWith([]))
    const { flow } = setup()

    await flow.start(payload)

    expect(flow.open.value).toBe(true)
    expect(flow.status.value).toBe('ready')
    expect(m.createMutate).not.toHaveBeenCalled()
    expect(m.updateMutate).not.toHaveBeenCalled()
    expect(m.previewAsync).toHaveBeenCalledWith({
      guid: null,
      payload: {
        client_id: 'c1',
        establishment_id: 'e1',
        protocol_id: 'p1',
        targets: [
          { guid: 'g1', target_ref: '0', target_date: '2026-09-23', name: null, animals_count: 1 },
          { target_ref: '1', target_date: '2026-09-30', name: null, animals_count: 0 },
        ],
        manager_profile_ids: ['m1'],
      },
    })
  })

  it('without critical warnings one click saves', async () => {
    m.previewAsync.mockResolvedValue(previewWith([info]))
    const { flow } = setup()
    await flow.start(payload)

    expect(flow.requiresAcknowledgement.value).toBe(false)
    flow.confirmAndSave()

    expect(m.createMutate).toHaveBeenCalledTimes(1)
  })

  it('with critical warnings confirm requires the acknowledgement', async () => {
    m.previewAsync.mockResolvedValue(previewWith([critical]))
    const { flow } = setup()
    await flow.start(payload)

    expect(flow.requiresAcknowledgement.value).toBe(true)
    flow.confirmAndSave()
    expect(m.createMutate).not.toHaveBeenCalled()

    flow.acknowledged.value = true
    flow.confirmAndSave()
    expect(m.createMutate).toHaveBeenCalledTimes(1)
  })

  it('edit mode previews and saves against the program guid', async () => {
    m.previewAsync.mockResolvedValue(previewWith([]))
    const { flow } = setup('prog-1')
    await flow.start(payload)
    flow.confirmAndSave()

    expect(m.previewAsync.mock.calls[0][0].guid).toBe('prog-1')
    expect(m.updateMutate).toHaveBeenCalledWith({ guid: 'prog-1', payload }, expect.any(Object))
    expect(m.createMutate).not.toHaveBeenCalled()
  })

  it('a preview error offers retry and saving without summary', async () => {
    m.previewAsync.mockRejectedValueOnce(new Error('boom'))
    const { flow } = setup()
    await flow.start(payload)

    expect(flow.status.value).toBe('error')
    expect(flow.canConfirm.value).toBe(false)

    flow.saveWithoutPreview()
    expect(m.createMutate).toHaveBeenCalledTimes(1)

    m.previewAsync.mockResolvedValueOnce(previewWith([]))
    await flow.retry()
    expect(flow.status.value).toBe('ready')
  })

  it('a save response with critical warnings redirects to the alerts tab', async () => {
    m.previewAsync.mockResolvedValue(previewWith([]))
    const { flow, onSaved } = setup()
    await flow.start(payload)
    flow.confirmAndSave()

    const options = m.createMutate.mock.calls[0][1]
    options.onSuccess({ guid: 'new-1', warnings: [critical] })

    expect(m.push).toHaveBeenCalledWith({
      name: 'vet-programs-detail',
      params: { vetGuid: 'vet-1', guid: 'new-1' },
      query: { tab: 'alerts' },
    })
    expect(m.warning).toHaveBeenCalled()
    expect(onSaved).not.toHaveBeenCalled()
    expect(flow.open.value).toBe(false)
  })

  it('a save response without critical warnings goes back to the list', async () => {
    m.previewAsync.mockResolvedValue(previewWith([]))
    const { flow, onSaved } = setup()
    await flow.start(payload)
    flow.confirmAndSave()

    m.createMutate.mock.calls[0][1].onSuccess({ guid: 'new-1', warnings: [info] })

    expect(onSaved).toHaveBeenCalledTimes(1)
    expect(m.push).not.toHaveBeenCalled()
  })

  it('a save error closes the panel so field errors are visible', async () => {
    m.previewAsync.mockResolvedValue(previewWith([]))
    const { flow } = setup()
    await flow.start(payload)
    flow.confirmAndSave()

    m.createMutate.mock.calls[0][1].onError()

    expect(flow.open.value).toBe(false)
  })
})
