import { beforeEach, describe, expect, it, vi } from 'vitest'
import { withSetup } from '@/test/with-setup'
import { useSyncEstablishmentStaff } from './useSyncEstablishmentStaff'
import { syncEstablishmentStaffApi } from '../api/clients.api'

vi.mock('../api/clients.api', () => ({
  syncEstablishmentStaffApi: vi.fn(),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/core/composables/useNotification', () => ({
  useNotification: () => notify,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { vetGuid: 'vet-1' } }),
}))

const variables = {
  clientGuid: 'client-1',
  estGuid: 'est-1',
  payload: { user_profile_guids: ['p1', 'p2'] },
}

describe('useSyncEstablishmentStaff', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('calls the tenant API with vet, client, establishment and payload', async () => {
    vi.mocked(syncEstablishmentStaffApi).mockResolvedValue({} as never)
    const { result } = withSetup(() => useSyncEstablishmentStaff())

    await result.mutateAsync(variables)

    expect(syncEstablishmentStaffApi).toHaveBeenCalledWith('vet-1', 'client-1', 'est-1', {
      user_profile_guids: ['p1', 'p2'],
    })
  })

  it('allows syncing an empty list of staff', async () => {
    vi.mocked(syncEstablishmentStaffApi).mockResolvedValue({} as never)
    const { result } = withSetup(() => useSyncEstablishmentStaff())

    await result.mutateAsync({ ...variables, payload: { user_profile_guids: [] } })

    expect(syncEstablishmentStaffApi).toHaveBeenCalledWith('vet-1', 'client-1', 'est-1', { user_profile_guids: [] })
  })

  it('invalidates the related query keys and notifies success', async () => {
    vi.mocked(syncEstablishmentStaffApi).mockResolvedValue({} as never)
    const { result, queryClient } = withSetup(() => useSyncEstablishmentStaff())
    const spy = vi.spyOn(queryClient, 'invalidateQueries')

    await result.mutateAsync(variables)

    const keys = spy.mock.calls.map(([filters]) => (filters as { queryKey?: unknown }).queryKey)
    expect(keys).toEqual(
      expect.arrayContaining([
        ['client-establishments', 'vet-1', 'client-1'],
        ['client-staff', 'vet-1', 'client-1'],
        ['program-manager-options', 'vet-1', 'client-1'],
        ['client', 'vet-1', 'client-1'],
        ['programs'],
        ['program'],
      ]),
    )
    expect(notify.success).toHaveBeenCalledTimes(1)
  })

  it('does not invalidate nor notify success when the API fails', async () => {
    vi.mocked(syncEstablishmentStaffApi).mockRejectedValue({ status: 500, message: 'boom' })
    const { result, queryClient } = withSetup(() => useSyncEstablishmentStaff())
    const spy = vi.spyOn(queryClient, 'invalidateQueries')

    await expect(result.mutateAsync(variables)).rejects.toBeTruthy()

    expect(spy).not.toHaveBeenCalled()
    expect(notify.success).not.toHaveBeenCalled()
    expect(notify.error).toHaveBeenCalledTimes(1)
    expect(result.generalError.value).toBe('boom')
  })

  it('exposes the server message on staff_client_mismatch (422)', async () => {
    vi.mocked(syncEstablishmentStaffApi).mockRejectedValue({
      status: 422,
      message: 'Perfiles ajenos',
      errors: { reason: 'staff_client_mismatch' },
    })
    const { result } = withSetup(() => useSyncEstablishmentStaff())

    await expect(result.mutateAsync(variables)).rejects.toBeTruthy()

    expect(result.generalError.value).toBe('Perfiles ajenos')
    expect(result.fieldErrors.value).toBeNull()
    expect(notify.error).toHaveBeenCalledWith('Perfiles ajenos')
  })
})
