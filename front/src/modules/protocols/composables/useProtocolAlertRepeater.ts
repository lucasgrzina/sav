import { ref } from 'vue'
import type { ProtocolTaskAlertFormValues } from '../validators/protocol.validator'

function emptyAlert(): ProtocolTaskAlertFormValues {
  return {
    offset_days: 0,
    time_of_day: 'before',
    time: '',
    roles: [],
    message: '',
    require_confirmation: false,
  }
}

export function useProtocolAlertRepeater(initial: ProtocolTaskAlertFormValues[] = []) {
  const alerts = ref<ProtocolTaskAlertFormValues[]>(initial.map((a) => ({ ...a })))

  function add() {
    alerts.value.push(emptyAlert())
  }

  function remove(index: number) {
    alerts.value.splice(index, 1)
  }

  function moveUp(index: number) {
    if (index <= 0) return
    const items = alerts.value
    ;[items[index - 1], items[index]] = [items[index], items[index - 1]]
  }

  function moveDown(index: number) {
    if (index >= alerts.value.length - 1) return
    const items = alerts.value
    ;[items[index], items[index + 1]] = [items[index + 1], items[index]]
  }

  function setItems(newAlerts: ProtocolTaskAlertFormValues[]) {
    alerts.value = newAlerts.map((a) => ({ ...a }))
  }

  function reset() {
    alerts.value = []
  }

  return { alerts, add, remove, moveUp, moveDown, setItems, reset }
}
