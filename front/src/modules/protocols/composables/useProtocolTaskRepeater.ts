import { ref } from 'vue'
import type { ProtocolTaskFormValues } from '../validators/protocol.validator'

function emptyTask(): ProtocolTaskFormValues {
  return {
    description: '',
    days_offset: 0,
    time_of_day: 'before',
    time: '',
    important: false,
    alerts: [],
  }
}

export function useProtocolTaskRepeater(initial: ProtocolTaskFormValues[] = []) {
  const tasks = ref<ProtocolTaskFormValues[]>(initial.map((t) => ({ ...t, alerts: [...t.alerts] })))

  function add() {
    tasks.value.push(emptyTask())
  }

  function remove(index: number) {
    tasks.value.splice(index, 1)
  }

  function moveUp(index: number) {
    if (index <= 0) return
    const items = tasks.value
    ;[items[index - 1], items[index]] = [items[index], items[index - 1]]
  }

  function moveDown(index: number) {
    if (index >= tasks.value.length - 1) return
    const items = tasks.value
    ;[items[index], items[index + 1]] = [items[index + 1], items[index]]
  }

  function setItems(newTasks: ProtocolTaskFormValues[]) {
    tasks.value = newTasks.map((t) => ({ ...t, alerts: [...t.alerts] }))
  }

  function reset() {
    tasks.value = []
  }

  return { tasks, add, remove, moveUp, moveDown, setItems, reset }
}
