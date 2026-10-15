import { describe, expect, it } from 'vitest'
import { formatCount, formatDayLabel, formatPercent } from './useDashboardFormat'

describe('useDashboardFormat', () => {
  it('formats an ISO date as dd/MM without timezone shifts', () => {
    expect(formatDayLabel('2026-10-14')).toBe('14/10')
    expect(formatDayLabel('2026-01-01')).toBe('01/01')
  })

  it('formats percentages with es-AR decimals and a dash for null', () => {
    expect(formatPercent(92.5)).toBe('92,5 %')
    expect(formatPercent(100)).toBe('100,0 %')
    expect(formatPercent(null)).toBe('—')
  })

  it('formats counts with es-AR thousands separators', () => {
    expect(formatCount(1203)).toBe('1.203')
  })
})
