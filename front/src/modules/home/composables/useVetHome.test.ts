import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { withSetup } from '@/test/with-setup'
import { VET_HOME_KEY, VET_HOME_STALE_MS, useVetHome } from './useVetHome'
import { getVetHomeApi } from '../api/home.api'
import type { VetHomeData } from '../types/home.types'

const s = vi.hoisted(() => ({ permissions: [] as string[] }))

vi.mock('@/core/composables/usePermissions', () => ({
  usePermission: () => ({ can: (p: string) => s.permissions.includes(p) }),
}))
vi.mock('../api/home.api', () => ({ getVetHomeApi: vi.fn() }))

const payload: VetHomeData = {
  generated_at: '2030-06-01T10:00:00Z',
  timezone: 'America/Argentina/Buenos_Aires',
  upcoming_programs: [],
  recent_alerts: null,
  problem_alerts: null,
  has_more: { upcoming_programs: false, recent_alerts: null, problem_alerts: null },
}

describe('useVetHome', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    s.permissions = ['programs.read']
    vi.mocked(getVetHomeApi).mockResolvedValue(payload)
  })

  it('fetches the home of the vet under a vet-scoped key with a 1 minute stale time', async () => {
    const { result, queryClient } = withSetup(() => useVetHome('vet-1'))
    await flushPromises()

    expect(getVetHomeApi).toHaveBeenCalledWith('vet-1', expect.anything())
    expect(result.data.value).toEqual(payload)
    expect(VET_HOME_STALE_MS).toBe(60_000)
    expect(queryClient.getQueryData([VET_HOME_KEY, 'vet-1'])).toEqual(payload)
  })

  it('does not fetch without programs.read', async () => {
    s.permissions = []
    withSetup(() => useVetHome('vet-1'))
    await flushPromises()

    expect(getVetHomeApi).not.toHaveBeenCalled()
  })

  it('does not fetch without a vet guid and refetches under another key when the vet changes', async () => {
    withSetup(() => useVetHome(''))
    await flushPromises()
    expect(getVetHomeApi).not.toHaveBeenCalled()

    const guid = ref('vet-1')
    const { queryClient } = withSetup(() => useVetHome(guid))
    await flushPromises()
    guid.value = 'vet-2'
    await flushPromises()

    expect(getVetHomeApi).toHaveBeenLastCalledWith('vet-2', expect.anything())
    expect(queryClient.getQueryData([VET_HOME_KEY, 'vet-1'])).toEqual(payload)
    expect(queryClient.getQueryData([VET_HOME_KEY, 'vet-2'])).toEqual(payload)
  })
})
