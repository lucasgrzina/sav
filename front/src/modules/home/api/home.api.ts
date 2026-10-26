import { http } from '@/core/api/http'
import type { VetHomeData } from '../types/home.types'

export async function getVetHomeApi(vetGuid: string, signal?: AbortSignal): Promise<VetHomeData> {
  const res = await http.get<VetHomeData>(`/v1/vets/${vetGuid}/home`, { signal })
  return res.data
}
