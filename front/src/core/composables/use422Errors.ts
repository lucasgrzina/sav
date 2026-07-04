export function use422Errors(setErrors: (errors: Record<string, string>) => void) {
  function applyServerErrors(err: unknown): boolean {
    const e = err as { errors?: Record<string, string[]> | null }
    if (!e?.errors) return false
    setErrors(
      Object.fromEntries(Object.entries(e.errors).map(([key, msgs]) => [key, msgs[0]])),
    )
    return true
  }
  return { applyServerErrors }
}
