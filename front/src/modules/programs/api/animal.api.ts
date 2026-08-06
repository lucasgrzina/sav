import { http } from '@/core/api/http'
import type { AnimalOption } from '../types/program.types'

export async function searchAnimalsApi(
  vetGuid: string,
  clientGuid: string,
  search: string,
  signal?: AbortSignal,
): Promise<AnimalOption[]> {
  const res = await http.get<AnimalOption[]>(
    `/v1/vets/${vetGuid}/clients/${clientGuid}/animals`,
    { params: { search }, signal },
  )
  return res.data
}
