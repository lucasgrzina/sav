import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { formatDate, parseDate } from './date'

describe('date utils', () => {
  // Typed access to the Node env without pulling @types/node into the front tsconfig.
  const env = (globalThis as unknown as { process: { env: Record<string, string | undefined> } }).process.env
  const originalTz = env.TZ

  beforeAll(() => {
    // Negative UTC offset: where `new Date('YYYY-MM-DD')` (UTC) used to show the previous day.
    env.TZ = 'America/Argentina/Buenos_Aires'
  })

  afterAll(() => {
    if (originalTz === undefined) delete env.TZ
    else env.TZ = originalTz
  })

  it('formats a date-only string without shifting the day', () => {
    expect(formatDate('2026-09-23')).toBe('23/09/2026')
    expect(formatDate('2026-01-01')).toBe('01/01/2026')
    expect(formatDate('2026-12-31')).toBe('31/12/2026')
  })

  it('parses a date-only string as local midnight', () => {
    const parsed = parseDate('2026-09-23')

    expect(parsed.getFullYear()).toBe(2026)
    expect(parsed.getMonth()).toBe(8)
    expect(parsed.getDate()).toBe(23)
    expect(parsed.getHours()).toBe(0)
  })

  it('keeps formatting full ISO datetimes in the local zone', () => {
    // 15:30 UTC is 12:30 in UTC-3: same calendar day.
    expect(formatDate('2026-09-23T15:30:00Z')).toBe('23/09/2026')
    // 01:00 UTC is still the previous day in UTC-3.
    expect(formatDate('2026-09-23T01:00:00Z')).toBe('22/09/2026')
  })

  it('accepts Date instances', () => {
    expect(formatDate(new Date(2026, 8, 23))).toBe('23/09/2026')
  })
})
