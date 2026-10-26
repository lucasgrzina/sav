import { describe, expect, it } from 'vitest'
import type { SimulatedTask } from '../types/protocol.types'
import { buildScheduleReviewRows, formatIntervalHours, getWorkSlotLabel, hoursBetween } from './protocol-schedule'

const task = (guid: string, date: string, time: string): SimulatedTask => ({
  guid,
  description: guid,
  important: false,
  computed_date: date,
  computed_time: time,
  alerts: [],
})

describe('getWorkSlotLabel', () => {
  it.each([
    ['04:00', 'Bien temprano a la mañana'],
    ['06:59', 'Bien temprano a la mañana'],
    ['07:00', 'A media mañana'],
    ['11:59', 'A media mañana'],
    ['12:00', 'Bien temprano a la tarde'],
    ['14:59', 'Bien temprano a la tarde'],
    ['15:00', 'A media tarde'],
    ['17:29', 'A media tarde'],
    ['17:30', 'Después de media tarde'],
    ['18:00', 'Después de media tarde'],
  ])('%s -> %s', (time, label) => {
    expect(getWorkSlotLabel(time)).toBe(`${label}, idealmente`)
  })
})

describe('intervals', () => {
  it('retiro day -3 18:00 -> IA day 0 06:00 = 60 h', () => {
    expect(hoursBetween('2026-01-07T18:00', '2026-01-10T06:00')).toBe(60)
  })

  it('day 0 06:00 -> day +1 04:00 = 22 h', () => {
    expect(hoursBetween('2026-01-10T06:00', '2026-01-11T04:00')).toBe(22)
  })

  it('formats hours', () => {
    expect(formatIntervalHours(60)).toBe('60 h')
    expect(formatIntervalHours(1.5)).toBe('1.5 h')
  })

  it('builds rows with em dash for the first and 0 h for same instant', () => {
    const rows = buildScheduleReviewRows([
      task('a', '2026-01-07', '18:00'),
      task('b', '2026-01-10', '06:00'),
      task('c', '2026-01-10', '06:00'),
      task('d', '2026-01-11', '04:00'),
    ])
    expect(rows.map((r) => r.interval)).toEqual(['—', '60 h', '0 h', '22 h'])
    expect(rows[0].workSlot).toBe('Después de media tarde, idealmente')
  })
})
