export type TutorialSource = 'youtube' | 'vimeo'

export interface Tutorial {
  guid: string
  title: string
  description: string | null
  source: TutorialSource
  code: string
  order: number
  created_at: string
}

export interface CreateTutorialPayload {
  title: string
  description: string | null
  source: TutorialSource
  code: string
  order: number
}

export interface UpdateTutorialPayload {
  title: string
  description: string | null
  source: TutorialSource
  code: string
  order: number
}
