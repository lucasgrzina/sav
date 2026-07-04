export function formatDate(date: string | Date, locale = 'es-AR'): string {
  return new Date(date).toLocaleDateString(locale, {
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
