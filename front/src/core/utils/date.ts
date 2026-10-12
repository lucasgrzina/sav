const DATE_ONLY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/

/**
 * Parses a value into a Date. Date-only strings (YYYY-MM-DD) are built as LOCAL midnight:
 * `new Date('YYYY-MM-DD')` is interpreted as UTC and shows the previous day in negative-offset
 * time zones (e.g. UTC-3). Full ISO datetimes keep the native parsing.
 */
export function parseDate(date: string | Date): Date {
  if (typeof date === 'string') {
    const match = DATE_ONLY_PATTERN.exec(date)
    if (match) {
      return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
    }
  }
  return new Date(date)
}

export function formatDate(date: string | Date, locale = 'es-AR'): string {
  return parseDate(date).toLocaleDateString(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  })
}

export function formatRelative(date: string | Date): string {
  const diff = Date.now() - new Date(date).getTime()
  const minutes = Math.floor(diff / 60000)
  if (minutes < 60) return `hace ${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `hace ${hours}h`
  return formatDate(date)
}
