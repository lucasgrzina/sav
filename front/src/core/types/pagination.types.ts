export interface PaginationParams {
  page: number
  per_page: number
}

export interface PaginatedResponse<T> {
  data: T[]
  total: number
  current_page: number
  per_page: number
  last_page: number
}
