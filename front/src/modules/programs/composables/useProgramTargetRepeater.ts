import { ref } from 'vue'
import type { ProgramTargetFormValues } from '../validators/program.validator'

function emptyTarget(): ProgramTargetFormValues {
  return {
    target_date: '',
    animals: [],
  }
}

export function useProgramTargetRepeater(initial: ProgramTargetFormValues[] = []) {
  const targets = ref<ProgramTargetFormValues[]>(
    initial.length ? initial.map((t) => ({ ...t, animals: [...t.animals] })) : [emptyTarget()],
  )

  function add() {
    targets.value.push(emptyTarget())
  }

  // Mínimo 1 fila siempre presente: si se intenta quitar la última fila, se limpia en vez de eliminarla.
  function remove(index: number) {
    if (targets.value.length <= 1) {
      targets.value.splice(index, 1, emptyTarget())
      return
    }
    targets.value.splice(index, 1)
  }

  function setItems(newTargets: ProgramTargetFormValues[]) {
    targets.value = newTargets.length
      ? newTargets.map((t) => ({ ...t, animals: [...t.animals] }))
      : [emptyTarget()]
  }

  function reset() {
    targets.value = [emptyTarget()]
  }

  return { targets, add, remove, setItems, reset }
}
