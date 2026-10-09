import { computed, watch } from 'vue'
import type { Ref } from 'vue'
import { useProvinces } from './useProvinces'

// Marker for a legacy free-text state that matches no province: shown, never submitted as a province.
export const LEGACY_STATE_VALUE = '__legacy_state__'

type MaybeText = Ref<string | null | undefined>

export interface UseEstablishmentProvinceOptions {
  countryGuid: Ref<string | undefined>
  provinceGuid: MaybeText
  state: MaybeText
  // Called after the user picks or clears a province (e.g. to reschedule geocoding).
  onUserChange?: () => void
}

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase()
}

/**
 * Province select state for the establishment forms.
 * Keeps `province_guid` and the legacy text `state` (province name) in sync, and tries to match
 * a legacy `state` text to a province by name when the form has no province yet.
 */
export function useEstablishmentProvince(options: UseEstablishmentProvinceOptions) {
  const { countryGuid, provinceGuid, state, onUserChange } = options
  const { data: provinces, isLoading } = useProvinces(countryGuid)

  const isLegacyUnmatched = computed(() =>
    !provinceGuid.value && (state.value ?? '').trim() !== '',
  )

  const selectOptions = computed(() => {
    const items = (provinces.value ?? []).map((p) => ({ value: p.guid, label: p.name }))
    if (isLegacyUnmatched.value) {
      items.unshift({ value: LEGACY_STATE_VALUE, label: state.value as string })
    }
    return items
  })

  const selectValue = computed<string | undefined>(() => {
    if (provinceGuid.value) return provinceGuid.value
    return isLegacyUnmatched.value ? LEGACY_STATE_VALUE : undefined
  })

  // Existing establishment with only the legacy text: link it to the province with the same name.
  function matchLegacyState(): void {
    if (provinceGuid.value || !state.value || !provinces.value) return
    const wanted = normalize(state.value)
    const match = provinces.value.find((p) => normalize(p.name) === wanted)
    if (match) {
      provinceGuid.value = match.guid
      state.value = match.name
    }
  }

  watch(provinces, matchLegacyState, { immediate: true })

  function handleChange(value: string | undefined): void {
    if (!value) {
      provinceGuid.value = null
      state.value = null
    } else if (value !== LEGACY_STATE_VALUE) {
      const province = provinces.value?.find((p) => p.guid === value)
      provinceGuid.value = value
      state.value = province?.name ?? null
    }
    onUserChange?.()
  }

  return { isLoading, selectOptions, selectValue, handleChange, matchLegacyState }
}
