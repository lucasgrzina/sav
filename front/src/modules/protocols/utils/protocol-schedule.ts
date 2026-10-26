import dayjs from 'dayjs'
import type { SimulatedTask } from '../types/protocol.types'

/** Work slot (franja) label from a clock time "HH:mm". Always flagged as ideal. */
export function getWorkSlotLabel(time: string): string {
  const [h, m] = time.split(':').map(Number)
  const minutes = h * 60 + (m || 0)

  let label: string
  if (minutes >= 17 * 60 + 30) label = 'Después de media tarde'
  else if (minutes >= 15 * 60) label = 'A media tarde'
  else if (minutes >= 12 * 60) label = 'Bien temprano a la tarde'
  else if (minutes >= 7 * 60) label = 'A media mañana'
  else label = 'Bien temprano a la mañana'

  return `${label}, idealmente`
}

/** Hours between two datetimes (full precision, may be fractional). */
export function hoursBetween(from: string, to: string): number {
  return dayjs(to).diff(dayjs(from), 'minute') / 60
}

export function formatIntervalHours(hours: number): string {
  const rounded = Math.round(hours * 100) / 100
  return `${rounded} h`
}

export interface ScheduleReviewRow {
  guid: string
  description: string
  date: string
  workSlot: string
  time: string
  interval: string
  alerts: { guid: string; date: string; time: string }[]
}

/** Builds review rows (tasks assumed chronologically ordered) with the interval since the previous task. */
export function buildScheduleReviewRows(tasks: SimulatedTask[]): ScheduleReviewRow[] {
  return tasks.map((task, index) => {
    const prev = tasks[index - 1]
    const interval = prev
      ? formatIntervalHours(
          hoursBetween(`${prev.computed_date}T${prev.computed_time}`, `${task.computed_date}T${task.computed_time}`),
        )
      : '—'

    return {
      guid: task.guid,
      description: task.description,
      date: task.computed_date,
      workSlot: getWorkSlotLabel(task.computed_time),
      time: task.computed_time,
      interval,
      alerts: task.alerts.map((a) => ({ guid: a.guid, date: a.computed_date, time: a.computed_time })),
    }
  })
}
