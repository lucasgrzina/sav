import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { withSetup } from '@/test/with-setup'
import { ADMIN_DASHBOARD_KEY, ADMIN_DASHBOARD_STALE_MS, useAdminDashboard } from './useAdminDashboard'
import { getAdminDashboardApi } from '../api/dashboard.api'
import type { AdminDashboardData } from '../types/dashboard.types'

vi.mock('../api/dashboard.api', () => ({ getAdminDashboardApi: vi.fn() }))

const payload: AdminDashboardData = {
  generated_at: '2026-10-14T12:00:00Z',
  vets: { active: 1, pending: 2, suspended: 3, total: 6 },
  totals: { clients: 4, establishments: 5, animals: 6, active_programs: 7 },
  sent_last_7d: [{ date: '2026-10-14', count: 9 }],
  delivery_30d: { rate: null, by_channel: [] },
}

describe('useAdminDashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(getAdminDashboardApi).mockResolvedValue(payload)
  })

  it('fetches the dashboard under the admin key with a 5 minute stale time', async () => {
    const { result, queryClient } = withSetup(() => useAdminDashboard())
    await flushPromises()

    expect(getAdminDashboardApi).toHaveBeenCalledTimes(1)
    expect(result.data.value).toEqual(payload)
    expect(ADMIN_DASHBOARD_STALE_MS).toBe(300_000)
    expect(queryClient.getQueryData(ADMIN_DASHBOARD_KEY)).toEqual(payload)
  })

  it('does not fetch when disabled', async () => {
    withSetup(() => useAdminDashboard(false))
    await flushPromises()

    expect(getAdminDashboardApi).not.toHaveBeenCalled()
  })
})
