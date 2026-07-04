export interface ParsedApiError {
  fieldErrors: Record<string, string> | null
  message: string | null
}

export function parseApiError(err: unknown): ParsedApiError {
  const e = err as { errors?: Record<string, string[]> | null; message?: string }
  if (e?.errors) {
    return {
      fieldErrors: Object.fromEntries(
        Object.entries(e.errors).map(([k, msgs]) => [k, msgs[0]]),
      ),
      message: null,
    }
  }
  return { fieldErrors: null, message: e?.message ?? null }
}
