import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AlertStatusFilter, AlertType } from '../types/program-alerts.types'

// UI state only: server data lives in Vue Query.
export const useProgramAlertsUiStore = defineStore('program-alerts-ui', () => {
  const status = ref<AlertStatusFilter>('all')
  const profile = ref<string | null>(null)
  const target = ref<string | null>(null)
  const type = ref<AlertType | null>(null)
  const page = ref(1)
  const expandedKeys = ref<string[]>([])
  const programGuid = ref<string | null>(null)

  function reset() {
    status.value = 'all'
    profile.value = null
    target.value = null
    type.value = null
    page.value = 1
    expandedKeys.value = []
  }

  // Filters belong to one program: switching program starts clean.
  function bindProgram(guid: string) {
    if (programGuid.value !== guid) {
      programGuid.value = guid
      reset()
    }
  }

  function setStatus(value: AlertStatusFilter) {
    status.value = value
    page.value = 1
  }

  function setProfile(value: string | null) {
    profile.value = value
    page.value = 1
  }

  function setTarget(value: string | null) {
    target.value = value
    page.value = 1
  }

  function setType(value: AlertType | null) {
    type.value = value
    page.value = 1
  }

  function setPage(value: number) {
    page.value = value
  }

  function toggleExpanded(key: string) {
    expandedKeys.value = expandedKeys.value.includes(key)
      ? expandedKeys.value.filter((k) => k !== key)
      : [...expandedKeys.value, key]
  }

  return {
    status, profile, target, type, page, expandedKeys,
    reset, bindProgram, setStatus, setProfile, setTarget, setType, setPage, toggleExpanded,
  }
})
