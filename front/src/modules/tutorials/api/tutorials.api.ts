import { http } from '@/core/api/http'
import type { Tutorial, CreateTutorialPayload, UpdateTutorialPayload } from '../types/tutorial.types'

export async function listTutorialsApi(signal?: AbortSignal): Promise<Tutorial[]> {
  const response = await http.get<Tutorial[]>('/v1/tutorials', { signal })
  return response.data
}

export async function createTutorialApi(payload: CreateTutorialPayload): Promise<Tutorial> {
  const response = await http.post<Tutorial>('/v1/tutorials', payload)
  return response.data
}

export async function updateTutorialApi(guid: string, payload: UpdateTutorialPayload): Promise<Tutorial> {
  const response = await http.put<Tutorial>(`/v1/tutorials/${guid}`, payload)
  return response.data
}

export async function deleteTutorialApi(guid: string): Promise<void> {
  await http.delete(`/v1/tutorials/${guid}`)
}
