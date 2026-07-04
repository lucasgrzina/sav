import { http } from '@/core/api/http'
import type { UserVetItem } from '../types/user-vet.types'

export async function fetchUserVets(): Promise<UserVetItem[]> {
  const res = await http.get<UserVetItem[]>('/v1/user/vets')
  return res.data
}
