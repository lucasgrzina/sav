import { describe, expect, it } from 'vitest'
import { retryErrorMessage } from './useRetryProgramAlert'

describe('retryErrorMessage', () => {
  it('maps a 422 reason to its copy', () => {
    expect(retryErrorMessage({ status: 422, errors: { reason: 'recently_retried' } })).toBe(
      'Ya se reintentó hace poco. Esperá unos minutos.',
    )
    expect(retryErrorMessage({ status: 422, errors: { reason: 'already_delivered' } })).toBe('Ya llegó por correo')
  })

  it('falls back to a generic message for unknown reasons or other errors', () => {
    expect(retryErrorMessage({ status: 422, errors: { reason: 'weird' } })).toBe('No pudimos reenviar el aviso.')
    expect(retryErrorMessage({ status: 500 })).toBe('No pudimos reenviar el aviso.')
  })
})
