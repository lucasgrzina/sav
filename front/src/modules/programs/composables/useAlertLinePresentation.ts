import {
  ALERTS_COPY,
  ALERT_TYPE_LABEL,
  CANCEL_REASON_COPY,
  FAILURE_COPY,
  FALLBACK_COPY,
  STATE_HINT,
} from '../constants/alert-copy'
import type { ProgramAlertLine } from '../types/program-alerts.types'

// Presentation rules shared by the desktop table and the mobile card, so both show
// the same text for the same line. No business logic: it only maps backend codes to copy.
export function useAlertLinePresentation() {
  function statusNote(line: ProgramAlertLine): string | null {
    if (line.fallback.used) return FALLBACK_COPY

    if (line.state === 'failed' || line.state === 'not_sent') {
      return FAILURE_COPY[line.failure_code ?? 'unknown'].text
    }

    if (line.state === 'cancelled') {
      return line.cancel_reason ? CANCEL_REASON_COPY[line.cancel_reason] : null
    }

    // Delivered/read are only shown when the backend sends real data (see resource).
    return STATE_HINT[line.state] ?? null
  }

  function typeLabel(line: ProgramAlertLine): string {
    return ALERT_TYPE_LABEL[line.type] ?? line.type
  }

  function taskLabel(line: ProgramAlertLine): string {
    return line.task?.description ?? ALERTS_COPY.noTask
  }

  function formatDeliveredAt(iso: string | null): string | null {
    if (!iso) return null
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return null
    return date.toLocaleString('es-AR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  return { statusNote, typeLabel, taskLabel, formatDeliveredAt }
}
