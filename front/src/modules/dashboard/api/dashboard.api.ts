import { http } from '@/core/api/http'
import type { AdminDashboardData } from '../types/dashboard.types'

export async function getAdminDashboardApi(): Promise<AdminDashboardData> {
  const res = await http.get<AdminDashboardData>('/v1/admin/dashboard')
  return res.data
}
