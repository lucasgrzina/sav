import { describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { LEGACY_STATE_VALUE, useEstablishmentProvince } from './useEstablishmentProvince'
import type { ProvinceItem } from '../types/client.types'

const provinces = vi.hoisted(() => ({ data: null as unknown }))

vi.mock('./useProvinces', () => ({
  useProvinces: () => ({ data: provinces.data, isLoading: { value: false } }),
}))

function setup(initialState: string | null, list: ProvinceItem[] | undefined = [
  { guid: 'p-cba', name: 'Córdoba' },
  { guid: 'p-sf', name: 'Santa Fe' },
]) {
  provinces.data = ref(list)
  const provinceGuid = ref<string | null | undefined>(null)
  const state = ref<string | null | undefined>(initialState)
  const onUserChange = vi.fn()
  const api = useEstablishmentProvince({ countryGuid: ref('ar'), provinceGuid, state, onUserChange })
  return { api, provinceGuid, state, onUserChange }
}

describe('useEstablishmentProvince', () => {
  it('sets province_guid and state (name) when the user picks a province', () => {
    const { api, provinceGuid, state, onUserChange } = setup(null)

    api.handleChange('p-sf')

    expect(provinceGuid.value).toBe('p-sf')
    expect(state.value).toBe('Santa Fe')
    expect(onUserChange).toHaveBeenCalledTimes(1)
  })

  it('clears both fields when the select is cleared', () => {
    const { api, provinceGuid, state } = setup('Córdoba')

    api.handleChange(undefined)

    expect(provinceGuid.value).toBeNull()
    expect(state.value).toBeNull()
  })

  it('matches a legacy state text to a province ignoring case and accents', () => {
    const { provinceGuid, state } = setup('cordoba')

    expect(provinceGuid.value).toBe('p-cba')
    expect(state.value).toBe('Córdoba')
  })

  it('shows an unmatched legacy text as a placeholder option without touching the form', () => {
    const { api, provinceGuid, state } = setup('Zona Norte')

    expect(provinceGuid.value).toBeNull()
    expect(api.selectValue.value).toBe(LEGACY_STATE_VALUE)
    expect(api.selectOptions.value[0]).toEqual({ value: LEGACY_STATE_VALUE, label: 'Zona Norte' })

    api.handleChange(LEGACY_STATE_VALUE)
    expect(state.value).toBe('Zona Norte')
  })

  it('matches the legacy text once the provinces finish loading', async () => {
    const list = ref<ProvinceItem[] | undefined>(undefined)
    provinces.data = list
    const provinceGuid = ref<string | null | undefined>(null)
    const state = ref<string | null | undefined>('Santa Fe')
    useEstablishmentProvince({ countryGuid: ref('ar'), provinceGuid, state })

    list.value = [{ guid: 'p-sf', name: 'Santa Fe' }]
    await nextTick()

    expect(provinceGuid.value).toBe('p-sf')
  })
})
