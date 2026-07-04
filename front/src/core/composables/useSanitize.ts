import DOMPurify from 'dompurify'

export function useSanitize() {
  function sanitize(html: string): string {
    return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } })
  }
  return { sanitize }
}
