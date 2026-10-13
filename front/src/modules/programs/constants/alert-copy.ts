import type {
  AlertCancelReason,
  AlertState,
  AlertStatusFilter,
  AlertType,
  FailureCode,
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
  recipient_removed: 'La persona ya no es responsable del programa.',
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
    detail: () => 'No hay responsables seleccionados: no se va a enviar ningún aviso.',
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
    title: 'Tarea sin alertas',
    detail: () => 'Hay tareas del protocolo que no tienen alertas configuradas.',
  },
}

export const SEVERITY_ORDER: Record<WarningSeverity, number> = {
  critical: 0,
  warning: 1,
  info: 2,
}
