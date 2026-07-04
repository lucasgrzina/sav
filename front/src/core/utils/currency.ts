export function formatCurrency(amount: number, currency = 'ARS', locale = 'es-AR'): string {
  return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount)
}

export function parseCurrency(value: string): number {
  return parseFloat(value.replace(/[^0-9.-]/g, ''))
}
