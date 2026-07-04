import { ref } from 'vue'
import type { TechniqueChild } from '../types/technique.types'

export function useSubTechniqueRepeater(initial: TechniqueChild[] = []) {
  const children = ref<TechniqueChild[]>(initial.map((c) => ({ ...c })))

  function addChild() {
    children.value.push({ name: '', protocols_name: null })
  }

  function removeChild(index: number) {
    children.value.splice(index, 1)
  }

  function updateChild(index: number, field: keyof TechniqueChild, value: string | null) {
    children.value[index] = { ...children.value[index], [field]: value }
  }

  function setChildren(newChildren: TechniqueChild[]) {
    children.value = newChildren.map((c) => ({ ...c }))
  }

  function reset() {
    children.value = []
  }

  return { children, addChild, removeChild, updateChild, setChildren, reset }
}
