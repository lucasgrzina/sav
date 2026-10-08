import { beforeEach, describe, expect, it, vi } from 'vitest'
import { withSetup } from '@/test/with-setup'
import { useAdminSyncEstablishmentStaff } from './useAdminSyncEstablishmentStaff'
import { adminSyncEstablishmentStaffApi } from '../../api/clients.api'

vi.mock('../../api/clients.api', () => ({
  adminSyncEstablishmentStaffApi: vi.fn(),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/core/composables/useNotification', () => ({
  useNotification: () => notify,
}))

const variables = {
  clientGuid: 'client-1',
  estGuid: 'est-1',
  payload: { user_profile_guids: ['p1'] },
}

describe('useAdminSyncEstablishmentStaff', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('calls the admin API with client, establishment and payload', async () => {
    vi.mocked(adminSyncEstablishmentStaffApi).mockResolvedValue({} as never)
    const { result } = withSetup(() => useAdminSyncEstablishmentStaff())

    await result.mutateAsync(variables)

    expect(adminSyncEstablishmentStaffApi).toHaveBeenCalledWith('client-1', 'est-1', { user_profile_guids: ['p1'] })
  })

  it('invalidates the admin query keys (manager options for any vet) and notifies success', async () => {
    vi.mocked(adminSyncEstablishmentStaffApi).mockResolvedValue({} as never)
    const { result, queryClient } = withSetup(() => useAdminSyncEstablishmentStaff())
    const spy = vi.spyOn(queryClient, 'invalidateQueries')

    await result.mutateAsync(variables)

    const keys = spy.mock.calls.map(([filters]) => (filters as { queryKey?: unknown }).queryKey)
    expect(keys).toEqual(
      expect.arrayContaining([
        ['admin-client-establishments', 'client-1'],
        ['admin-client-staff', 'client-1'],
        ['program-manager-options'],
        ['admin-client', 'client-1'],
        ['programs'],
        ['program'],
      ]),
    )
    expect(notify.success).toHaveBeenCalledTimes(1)
  })

  it('maps field errors from a validation failure', async () => {
    vi.mocked(adminSyncEstablishmentStaffApi).mockRejectedValue({
      status: 422,
      errors: { user_profile_guids: ['Invalido'] },
    })
    const { result } = withSetup(() => useAdminSyncEstablishmentStaff())

    await expect(result.mutateAsync(variables)).rejects.toBeTruthy()

    expect(result.fieldErrors.value).toEqual({ user_profile_guids: 'Invalido' })
    expect(notify.error).toHaveBeenCalledTimes(1)
  })

  it('exposes the server message on staff_client_mismatch (422)', async () => {
    vi.mocked(adminSyncEstablishmentStaffApi).mockRejectedValue({
      status: 422,
      message: 'Perfiles ajenos',
      errors: { reason: 'staff_client_mismatch' },
    })
    const { result } = withSetup(() => useAdminSyncEstablishmentStaff())

    await expect(result.mutateAsync(variables)).rejects.toBeTruthy()

    expect(result.generalError.value).toBe('Perfiles ajenos')
  })
})
