import type {
  AlertCancelReason,
  AlertState,
  AlertStatusFilter,
  AlertType,
  FailureCode,
  RetryBlockedReason,
  WarningCode,
  WarningSeverity,
} from '../types/program-alerts.types'

// DEC-14: all user-facing copy for program alerts lives here, keyed by backend codes.
// No copy mentions a reply or confirmation from the recipient.

export const STATE_LABEL: Record<AlertState, string> = {
  scheduled: 'Programado',
  in_process: 'En proceso',
  sent: 'Enviado',
  delivered: 'Entregado',
  read: 'Leído',
  failed: 'Con problema',
  not_sent: 'No enviado',
  cancelled: 'Cancelado',
}

export const STATE_COLOR: Record<AlertState, string> = {
  scheduled: 'blue',
  in_process: 'processing',
  sent: 'green',
  delivered: 'green',
  read: 'green',
  failed: 'red',
  not_sent: 'orange',
  cancelled: 'default',
}

// Only states that need a clarification (no real delivery data must be implied).
export const STATE_HINT: Partial<Record<AlertState, string>> = {
  sent: 'Aún sin confirmación de entrega',
  read: 'La lectura solo se informa si la persona tiene activada la confirmación de lectura en WhatsApp.',
}

export type FailureAction = 'go_to_contacts' | 'retry' | 'none'

export interface FailureCopyEntry {
  text: string
  action: FailureAction
}

export const FAILURE_COPY: Record<FailureCode, FailureCopyEntry> = {
  no_whatsapp_contact: {
    text: "No tiene un WhatsApp cargado para recibir avisos. Cargalo en Personal del cliente > Contactos y activá 'Usado para alertas'.",
    action: 'go_to_contacts',
  },
  no_email_contact: {
    text: 'No tiene un correo cargado para recibir avisos. Cargalo en Personal del cliente > Contactos y activá "Usado para alertas".',
    action: 'go_to_contacts',
  },
  invalid_number: {
    text: 'El número no tiene WhatsApp o no es válido. Revisalo en sus contactos.',
    action: 'go_to_contacts',
  },
  opted_out: {
    text: 'La persona pidió no recibir avisos por WhatsApp (respondió BAJA).',
    action: 'none',
  },
  provider_unreachable: {
    text: 'WhatsApp no respondió después de varios intentos.',
    action: 'retry',
  },
  provider_rejected: {
    text: 'WhatsApp rechazó el mensaje.',
    action: 'retry',
  },
  service_misconfigured: {
    text: 'Hubo un problema en el servicio de envío de SAV. Ya fue informado al equipo.',
    action: 'none',
  },
  no_longer_applicable: {
    text: 'No se envió porque el programa cambió o se canceló.',
    action: 'none',
  },
  duplicate: {
    text: 'No se envió porque la persona ya recibió este mismo aviso.',
    action: 'none',
  },
  rate_limited: {
    text: 'No se envió para no superar el límite de mensajes permitido.',
    action: 'retry',
  },
  quiet_hours: {
    text: 'No se envió porque caía fuera del horario permitido para avisos.',
    action: 'none',
  },
  unknown: {
    text: 'No se pudo enviar (sin más detalle).',
    action: 'retry',
  },
}

export const CANCEL_REASON_COPY: Record<AlertCancelReason, string> = {
  rescheduled: 'Se reprogramó por un cambio de fecha.',
  protocol_changed: 'El protocolo cambió y este aviso ya no aplica.',
  recipient_removed:
    'La persona dejó de ser responsable del programa (por ejemplo, por un cambio de encargado en el establecimiento). Asigná un nuevo responsable editando el programa.',
  group_removed: 'El grupo se quitó del programa.',
  program_cancelled: 'El programa se canceló.',
  superseded: 'Fue reemplazado por un aviso actualizado.',
}

export const FALLBACK_COPY = 'No llegó por WhatsApp — se envió por correo'

export const ALERT_TYPE_LABEL: Record<AlertType, string> = {
  'program.task_due': 'Recordatorio de tarea',
  'program.created': 'Aviso de creación',
  'program.cancelled': 'Cancelación',
  'program.pdf_shared': 'PDF compartido',
}

export const STATUS_FILTER_LABEL: Record<AlertStatusFilter, string> = {
  all: 'Todos',
  scheduled: 'Programados',
  sent: 'Enviados',
  problem: 'Con problema',
  cancelled: 'Cancelados',
}

export const ATTEMPT_CHANNEL_LABEL: Record<string, string> = {
  whatsapp: 'WhatsApp',
  email: 'Correo',
  push: 'Notificación',
}

export const ATTEMPT_STATE_LABEL: Record<string, string> = {
  pending: 'Pendiente',
  sent: 'Enviado',
  delivered: 'Entregado',
  read: 'Leído',
  failed: 'Con problema',
  suppressed: 'No enviado',
}

// Copy for the "Reenviar" button: why it is disabled (advice) and why the server refused (422 reason).
export const RETRY_REASON_COPY: Record<RetryBlockedReason, string> = {
  program_cancelled: 'El programa está cancelado.',
  alert_cancelled: 'El aviso fue cancelado y ya no corresponde enviarlo.',
  not_retryable: 'Este aviso no se puede reenviar.',
  opted_out: 'La persona se dio de baja de los avisos por WhatsApp.',
  already_delivered: 'Ya llegó por correo',
  recently_retried: 'Ya se reintentó hace poco. Esperá unos minutos.',
  contact_still_missing: 'Todavía no tiene un contacto válido. Cargalo y volvé a intentar.',
}

export const ACTIONS_COPY = {
  retry: 'Reenviar',
  retryConfirmTitle: 'Reenviar aviso',
  retryConfirmMessage: (name: string) => `Se va a intentar enviar de nuevo el aviso a ${name}.`,
  retrySuccess: 'El aviso se reenviará en instantes.',
  retryError: 'No pudimos reenviar el aviso.',
  goToContacts: 'Ir a contactos',
  rebuild: 'Recalcular avisos',
  rebuildSuccess: 'Avisos recalculados.',
  rebuildError: 'No pudimos recalcular los avisos.',
  outOfSync: 'Los avisos programados no coinciden con el plan actual',
  warningsBanner: (count: number) =>
    count === 1
      ? '1 aviso de este programa no se va a enviar.'
      : `${count} avisos de este programa no se van a enviar.`,
  warningsDetail: 'Ver detalle',
  warningsDrawerTitle: 'Avisos que no se van a enviar',
} as const

export const ALERTS_COPY = {
  tab: 'Avisos',
  scheduleTab: 'Cronograma',
  emptyTitle: 'Este programa todavía no tiene avisos programados.',
  emptyDetail:
    'Los avisos salen de las alertas del protocolo y de los responsables que elegiste. Revisá que el protocolo tenga alertas y que haya responsables seleccionados.',
  editProgram: 'Editar programa',
  emptyFilter: 'No hay avisos con este filtro.',
  emptyProblem: 'No hay avisos con problemas.',
  errorTitle: 'No pudimos cargar los avisos.',
  errorRetry: 'Reintentar',
  cancelledBanner: (date: string) =>
    `Programa cancelado el ${date}. Los avisos pendientes se cancelaron; abajo queda el historial.`,
  allPeople: 'Todas las personas',
  allGroups: 'Todos los grupos',
  allTypes: 'Todos los tipos',
  attempts: 'Intentos de envío',
  noTask: 'Sin tarea asociada',
} as const

export type WarningCopyContext = Record<string, unknown>

export interface WarningCopyEntry {
  title: string
  detail: (ctx: WarningCopyContext) => string
}

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' && value !== '' ? value : fallback
}

function num(value: unknown): number {
  return typeof value === 'number' ? value : 0
}

function roles(ctx: WarningCopyContext): string {
  return Array.isArray(ctx.roles) ? ctx.roles.filter((r): r is string => typeof r === 'string').join(', ') : ''
}

function taskNames(ctx: WarningCopyContext): string[] {
  if (!Array.isArray(ctx.items)) return []
  return ctx.items
    .map((i) => (i && typeof i === 'object' ? (i as Record<string, unknown>).description : null))
    .filter((d): d is string => typeof d === 'string' && d !== '')
}

export const WARNING_COPY: Record<WarningCode, WarningCopyEntry> = {
  ALERT_ROLE_WITHOUT_RECIPIENT: {
    title: 'Sin destinatarios para un rol',
    detail: (c) => `Ningún responsable seleccionado tiene el rol ${roles(c) || 'requerido'}, por lo que ese aviso no se va a enviar.`,
  },
  RECIPIENT_WITHOUT_WHATSAPP: {
    title: 'Responsable sin WhatsApp',
    detail: (c) => `${str(c.name, 'Una persona')} no tiene un WhatsApp cargado: no va a recibir ${num(c.count) || 'los'} avisos.`,
  },
  RECIPIENT_INVALID_NUMBER: {
    title: 'Número de WhatsApp inválido',
    detail: (c) => `El número de ${str(c.name, 'una persona')} no es válido: no va a recibir los avisos.`,
  },
  RECIPIENT_OPTED_OUT: {
    title: 'Persona dada de baja',
    detail: (c) => `${str(c.name, 'Una persona')} pidió no recibir avisos por WhatsApp.`,
  },
  ALERT_IN_PAST: {
    title: 'Avisos con fecha pasada',
    detail: (c) => `${num(c.count) || 'Algunos'} avisos caen en una fecha que ya pasó y no se van a enviar.`,
  },
  NO_MANAGERS_SELECTED: {
    title: 'Sin responsables',
    detail: () =>
      'El programa no tiene responsables asignados, por lo que no se va a enviar ningún aviso. Si cambiaste al encargado del establecimiento, editá el programa y seleccioná al nuevo responsable.',
  },
  RECIPIENT_EMAIL_ONLY: {
    title: 'Solo recibirá por correo',
    detail: (c) => `${str(c.name, 'Una persona')} no tiene WhatsApp, pero va a recibir los avisos por correo.`,
  },
  DUPLICATE_TARGET_DATE: {
    title: 'Fecha repetida',
    detail: () => 'Hay grupos con la misma fecha.',
  },
  HIGH_VOLUME_FOR_RECIPIENT: {
    title: 'Muchos avisos para una persona',
    detail: (c) => `${str(c.name, 'Una persona')} va a recibir ${num(c.count) || 'muchos'} avisos.`,
  },
  TASK_WITHOUT_ALERTS: {
    title: 'Tareas sin alertas',
    detail: (c) => {
      const names = taskNames(c)
      const count = Math.max(num(c.count), names.length)
      const list = names.length ? `: ${names.join(', ')}${count > names.length ? ` y ${count - names.length} más` : ''}.` : '.'
      return count === 1
        ? `1 tarea del protocolo no tiene alertas configuradas${list}`
        : `${count || 'Hay'} tareas del protocolo no tienen alertas configuradas${list}`
    },
  },
}

export const SEVERITY_ORDER: Record<WarningSeverity, number> = {
  critical: 0,
  warning: 1,
  info: 2,
}

// RF-09: copy of the "Revisá los avisos" panel shown before saving a program.
export const PREVIEW_COPY = {
  title: 'Revisá los avisos',
  loading: 'Calculando los avisos…',
  backToEdit: 'Volver a editar',
  confirm: 'Confirmar y guardar',
  acknowledge: 'Entiendo que estos avisos no se van a enviar',
  errorTitle: 'No pudimos generar el resumen',
  errorDetail: 'Podés reintentar o guardar el programa igual: los avisos se calculan también al guardar.',
  retry: 'Reintentar',
  saveWithoutSummary: 'Guardar sin resumen',
  viewMessage: 'Ver mensaje',
  messageTitle: 'Mensaje de WhatsApp',
  messageNote: 'Este es el texto exacto que va a recibir la persona.',
  willSend: 'Se enviará',
  willSendEmail: 'Se enviará por correo',
  wontSend: (reason: string) => `No se enviará — ${reason}`,
  itemSkip: {
    in_past: 'la fecha ya pasó',
    no_recipients: 'no hay destinatarios para este rol',
  },
  recipientSkip: {
    no_whatsapp_contact: 'no tiene WhatsApp cargado',
    invalid_number: 'el número no es válido',
    opted_out: 'pidió no recibir avisos',
  },
  recipientSentence: (name: string, role: string | null, channel: string, destination: string | null, when: string) =>
    `${name}${role ? ` (${role})` : ''} recibirá por ${channel}${destination ? ` (${destination})` : ''} el ${when}`,
  channelLabel: { whatsapp: 'WhatsApp', email: 'correo' } as Record<string, string>,
  summary: (total: number, people: number, from: string | null, to: string | null, wont: number) => {
    if (total > 0 && (people === 0 || wont >= total)) {
      return total === 1
        ? 'Se calculó 1 aviso, pero no se enviará.'
        : `Se calcularon ${total} avisos, pero ninguno se enviará.`
    }
    const base = `Se programarán ${total} ${total === 1 ? 'aviso' : 'avisos'} para ${people} ${people === 1 ? 'persona' : 'personas'}`
    const range = from && to ? ` entre el ${from} y el ${to}` : ''
    const skipped = wont > 0 ? ` ${wont === 1 ? 'El 1 no se enviará' : `Los ${wont} no se enviarán`}.` : ''
    return `${base}${range}.${skipped}`
  },
  groupTitle: (date: string, count: number) => `Grupo del ${date} — ${count} ${count === 1 ? 'aviso' : 'avisos'}`,
  showMore: (n: number) => `Mostrar ${n} más`,
  truncated: 'El resumen muestra solo una parte de los avisos; el programa se guardará completo.',
  managers: 'Responsables',
  client: 'Cliente',
  establishment: 'Establecimiento',
  protocol: 'Protocolo',
  technique: 'Técnica',
  diffTitle: 'Cambios respecto de lo ya programado',
  diffCounts: (kept: number, updated: number, created: number, cancelled: number) =>
    `Se conservan ${kept}, se reprograman ${updated}, se crean ${created} y se cancelan ${cancelled}.`,
  diffSentNotModified: (n: number) =>
    `${n} ${n === 1 ? 'aviso ya enviado no se modifica' : 'avisos ya enviados no se modifican'}.`,
  diffProtocolChanged: 'El protocolo cambió: los avisos se recalcularán con el nuevo plan.',
  savedWithCritical: 'Programa guardado. Algunos avisos no se van a enviar; revisalos en la pestaña Avisos.',
} as const
