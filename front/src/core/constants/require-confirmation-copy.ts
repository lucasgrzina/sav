// "Requires confirmation" is informational for now: the flag is stored and shown, but the
// system does not ask for nor record a recipient response. No confirmation message template
// exists yet, so nothing is sent to ask for it (planned for V2).
export const REQUIRE_CONFIRMATION_LABEL = 'Requiere confirmación (informativo)'

export const REQUIRE_CONFIRMATION_HELP =
  'Indica que el destinatario debería confirmar que ya realizó la tarea. ' +
  'Hoy esta opción no se usa: todavía no hay un mensaje de confirmación disponible, ' +
  'por lo que no se le pide ninguna respuesta y no se registra ninguna.'
