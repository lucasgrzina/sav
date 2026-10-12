export type OffsetDirection = 'before' | 'after'

function pluralizeDays(days: number): string {
  return `${days} ${days === 1 ? 'día' : 'días'}`
}

function normalizeDays(days: number | null | undefined): number {
  return Number.isFinite(days) ? Math.max(0, Math.trunc(days as number)) : 0
}

/**
 * Preview of when a protocol task happens, relative to the program target date.
 * `targetLabel` is the technique's custom target date name (e.g. "Fecha de servicio").
 */
export function describeTaskOffset(
  days: number | null | undefined,
  direction: OffsetDirection,
  targetLabel?: string | null,
): string {
  const n = normalizeDays(days)
  const reference = targetLabel?.trim()
    ? `la fecha objetivo del programa (${targetLabel.trim()})`
    : 'la fecha objetivo del programa'

  if (n === 0) return `La tarea se hará el mismo día de ${reference}`
  const when = direction === 'before' ? 'antes' : 'después'
  return `La tarea se hará ${pluralizeDays(n)} ${when} de ${reference}`
}

/** Preview of when an alert is sent, relative to the date of its task. */
export function describeAlertOffset(
  days: number | null | undefined,
  direction: OffsetDirection,
): string {
  const n = normalizeDays(days)
  if (n === 0) return 'Se enviará el mismo día de la tarea'
  const when = direction === 'before' ? 'antes' : 'después'
  return `Se enviará ${pluralizeDays(n)} ${when} de la tarea`
}
