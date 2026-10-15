const EMPTY = '—'

/** "2026-10-14" -> "14/10" (no Date parsing: avoids timezone day shifts). */
export function formatDayLabel(isoDate: string): string {
  const [, month, day] = isoDate.split('-')
  return month && day ? `${day}/${month}` : isoDate
}

/** 92.5 -> "92,5 %"; null -> "—". */
export function formatPercent(rate: number | null): string {
  if (rate === null) return EMPTY
  return `${rate.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`
}

export function formatCount(value: number): string {
  return value.toLocaleString('es-AR')
}
