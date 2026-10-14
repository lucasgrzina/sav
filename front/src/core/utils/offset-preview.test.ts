import { describe, expect, it } from 'vitest'
import { describeAlertOffset, describeTaskOffset } from './offset-preview'

describe('describeTaskOffset', () => {
  it('describes days before the program target date', () => {
    expect(describeTaskOffset(3, 'before')).toBe(
      'La tarea se hará 3 días antes de la fecha objetivo del programa',
    )
  })

  it('describes days after and uses singular for one day', () => {
    expect(describeTaskOffset(1, 'after')).toBe(
      'La tarea se hará 1 día después de la fecha objetivo del programa',
    )
  })

  it('describes the same day when offset is zero', () => {
    expect(describeTaskOffset(0, 'before')).toBe(
      'La tarea se hará el mismo día de la fecha objetivo del programa',
    )
  })

  it('names the target date when a label is provided', () => {
    expect(describeTaskOffset(3, 'after', 'Fecha de servicio')).toBe(
      'La tarea se hará 3 días después de la fecha objetivo del programa (Fecha de servicio)',
    )
  })

  it('treats empty or invalid values as zero', () => {
    expect(describeTaskOffset(null, 'after', '  ')).toBe(
      'La tarea se hará el mismo día de la fecha objetivo del programa',
    )
  })
})

describe('describeAlertOffset', () => {
  it('describes days before the task', () => {
    expect(describeAlertOffset(2, 'before')).toBe('Se enviará 2 días antes de la tarea')
  })

  it('describes days after the task', () => {
    expect(describeAlertOffset(1, 'after')).toBe('Se enviará 1 día después de la tarea')
  })

  it('describes the same day when offset is zero', () => {
    expect(describeAlertOffset(0, 'after')).toBe('Se enviará el mismo día de la tarea')
  })
})
