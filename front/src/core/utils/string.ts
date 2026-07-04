export function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase()
}

export function truncate(str: string, length: number): string {
  return str.length > length ? str.slice(0, length) + '…' : str
}

export function slugify(str: string): string {
  return str.toLowerCase().replace(/\s+/g, '-').replace(/[^\w-]/g, '')
}
