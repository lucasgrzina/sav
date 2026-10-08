import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { withSetup } from '@/test/with-setup'
import { PROGRAM_MANAGER_OPTIONS_KEY, useClientManagerOptions } from './useClientManagerOptions'
import { listClientManagerOptionsApi } from '../api/program.api'

vi.mock('../api/program.api', () => ({
  listClientManagerOptionsApi: vi.fn(),
}))

const route = vi.hoisted(() => ({ params: { vetGuid: 'vet-1' } as Record<string, string> }))
vi.mock('vue-router', () => ({ useRoute: () => route }))

const options = [{ guid: 'p1', name: 'Ana', role: 'client-manager' }]

describe('useClientManagerOptions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    route.params = { vetGuid: 'vet-1' }
    vi.mocked(listClientManagerOptionsApi).mockResolvedValue(options)
  })

  it('fetches the manager options of the chosen establishment', async () => {
    const { result } = withSetup(() => useClientManagerOptions('client-1', 'est-1'))
    await flushPromises()

    expect(listClientManagerOptionsApi).toHaveBeenCalledWith('vet-1', 'client-1', 'est-1')
    expect(result.data.value).toEqual(options)
  })

  it('does not fetch until an establishment is chosen', async () => {
    withSetup(() => useClientManagerOptions('client-1', ''))
    await flushPromises()

    expect(listClientManagerOptionsApi).not.toHaveBeenCalled()
  })

  it('does not fetch without a client', async () => {
    withSetup(() => useClientManagerOptions('', 'est-1'))
    await flushPromises()

    expect(listClientManagerOptionsApi).not.toHaveBeenCalled()
  })

  it('refetches with the new establishment when it changes', async () => {
    const establishment = ref('est-1')
    withSetup(() => useClientManagerOptions('client-1', establishment))
    await flushPromises()

    establishment.value = 'est-2'
    await flushPromises()

    expect(listClientManagerOptionsApi).toHaveBeenNthCalledWith(1, 'vet-1', 'client-1', 'est-1')
    expect(listClientManagerOptionsApi).toHaveBeenNthCalledWith(2, 'vet-1', 'client-1', 'est-2')
  })

  it('keeps the exported key prefix used by the staff mutations to invalidate it', () => {
    expect(PROGRAM_MANAGER_OPTIONS_KEY).toBe('program-manager-options')
  })
})
