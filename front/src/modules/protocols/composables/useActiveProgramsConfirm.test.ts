import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { activeProgramsConfirmMessage, useActiveProgramsConfirm } from './useActiveProgramsConfirm'

function setup(count: number | undefined, edit: boolean) {
  const proceed = vi.fn()
  const flow = useActiveProgramsConfirm<{ n: number }>(ref(count), ref(edit), proceed)
  return { proceed, ...flow }
}

describe('useActiveProgramsConfirm', () => {
  it('asks for confirmation in edit mode with active programs and saves only after confirm', () => {
    const { proceed, requestSubmit, confirmOpen, confirm } = setup(3, true)
    requestSubmit({ n: 1 })
    expect(confirmOpen.value).toBe(true)
    expect(proceed).not.toHaveBeenCalled()
    confirm()
    expect(proceed).toHaveBeenCalledWith({ n: 1 })
    expect(confirmOpen.value).toBe(false)
  })

  it('does not save when the confirmation is cancelled', () => {
    const { proceed, requestSubmit, cancel, confirmOpen } = setup(2, true)
    requestSubmit({ n: 1 })
    cancel()
    expect(confirmOpen.value).toBe(false)
    expect(proceed).not.toHaveBeenCalled()
  })

  it.each([
    ['zero programs', 0, true],
    ['count missing', undefined, true],
    ['create mode', 5, false],
  ])('submits directly with %s', (_label, count, edit) => {
    const { proceed, requestSubmit, confirmOpen } = setup(count, edit)
    requestSubmit({ n: 1 })
    expect(confirmOpen.value).toBe(false)
    expect(proceed).toHaveBeenCalledWith({ n: 1 })
  })

  it('builds the message with the count', () => {
    expect(activeProgramsConfirmMessage(4)).toBe(
      'Este protocolo se usa en 4 programas activos. Los avisos pendientes de esos programas se van a actualizar.',
    )
    expect(activeProgramsConfirmMessage(1)).toContain('1 programa activo')
  })
})
