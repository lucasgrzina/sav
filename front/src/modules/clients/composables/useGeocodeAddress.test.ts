import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useGeocodeAddress } from './useGeocodeAddress'
import type { GeocodeAddressPayload, GeocodeResult } from '../types/client.types'

function setup(initial: { address?: string | null; city?: string | null; lat?: number | null; lng?: number | null } = {}) {
  const address = ref<string | null | undefined>(initial.address ?? 'Ruta 5 km 100')
  const city = ref<string | null | undefined>(initial.city ?? null)
  const state = ref<string | null | undefined>(null)
  const zipCode = ref<string | null | undefined>(null)
  const latitude = ref<number | null | undefined>(initial.lat ?? null)
  const longitude = ref<number | null | undefined>(initial.lng ?? null)
  const fetcher = vi.fn<(payload: GeocodeAddressPayload, signal: AbortSignal) => Promise<GeocodeResult>>()
  const geo = useGeocodeAddress({ address, city, state, zipCode, latitude, longitude, fetcher })
  return { address, city, latitude, longitude, fetcher, geo }
}

describe('useGeocodeAddress', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('debounces: only the last of several edits triggers a request', async () => {
    const { fetcher, geo, latitude, longitude } = setup()
    fetcher.mockResolvedValue({ latitude: -35.1234567, longitude: -62.7654321 })

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(500)
    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(500)
    expect(fetcher).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(300)

    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(fetcher.mock.calls[0][0]).toEqual({ address: 'Ruta 5 km 100', city: null, state: null, zip_code: null })
    expect(latitude.value).toBe(-35.123457)
    expect(longitude.value).toBe(-62.765432)
    expect(geo.notFound.value).toBe(false)
    expect(geo.isGeocoding.value).toBe(false)
  })

  it('does not call the API when address and city are both empty', async () => {
    const { fetcher, geo } = setup({ address: '   ' })

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(2000)

    expect(fetcher).not.toHaveBeenCalled()
  })

  it('geocodes with only the city', async () => {
    const { fetcher, geo } = setup({ address: '', city: 'Trenque Lauquen' })
    fetcher.mockResolvedValue({ latitude: 1, longitude: 2 })

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)

    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('respects a manual edit: no automatic overwrite afterwards', async () => {
    const { fetcher, geo, latitude } = setup({ lat: 10, lng: 20 })
    fetcher.mockResolvedValue({ latitude: 1, longitude: 2 })

    geo.markManualEdit()
    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(2000)

    expect(geo.manuallyEdited.value).toBe(true)
    expect(fetcher).not.toHaveBeenCalled()
    expect(latitude.value).toBe(10)
  })

  it('a manual edit cancels a pending debounced lookup', async () => {
    const { fetcher, geo } = setup()
    fetcher.mockResolvedValue({ latitude: 1, longitude: 2 })

    geo.scheduleGeocode()
    geo.markManualEdit()
    await vi.advanceTimersByTimeAsync(2000)

    expect(fetcher).not.toHaveBeenCalled()
  })

  it('recalculate clears the manual flag and geocodes immediately', async () => {
    const { fetcher, geo, latitude, longitude } = setup({ lat: 10, lng: 20 })
    fetcher.mockResolvedValue({ latitude: 1, longitude: 2 })
    geo.markManualEdit()

    await geo.recalculate()

    expect(geo.manuallyEdited.value).toBe(false)
    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(latitude.value).toBe(1)
    expect(longitude.value).toBe(2)

    // Automatic mode is active again
    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('flags notFound and keeps current coordinates when there is no result', async () => {
    const { fetcher, geo, latitude, longitude } = setup({ lat: 10, lng: 20 })
    fetcher.mockResolvedValue({ latitude: null, longitude: null })

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)

    expect(geo.notFound.value).toBe(true)
    expect(latitude.value).toBe(10)
    expect(longitude.value).toBe(20)
  })

  it('treats a request failure as notFound without throwing', async () => {
    const { fetcher, geo } = setup()
    fetcher.mockRejectedValue(new Error('network'))

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)

    expect(geo.notFound.value).toBe(true)
    expect(geo.isGeocoding.value).toBe(false)
  })

  it('ignores stale out-of-order responses', async () => {
    const { fetcher, geo, latitude, address } = setup()
    const resolvers: Array<(r: GeocodeResult) => void> = []
    fetcher.mockImplementation(() => new Promise<GeocodeResult>((resolve) => { resolvers.push(resolve) }))

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)
    address.value = 'Otra calle 2'
    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)
    expect(fetcher).toHaveBeenCalledTimes(2)
    expect(fetcher.mock.calls[0][1].aborted).toBe(true)

    resolvers[1]({ latitude: 2, longitude: 2 })
    await vi.advanceTimersByTimeAsync(0)
    resolvers[0]({ latitude: 1, longitude: 1 })
    await vi.advanceTimersByTimeAsync(0)

    expect(latitude.value).toBe(2)
    expect(geo.isGeocoding.value).toBe(false)
  })

  it('shows the loading state while the request is in flight', async () => {
    const { fetcher, geo } = setup()
    let resolve!: (r: GeocodeResult) => void
    fetcher.mockImplementation(() => new Promise<GeocodeResult>((r) => { resolve = r }))

    geo.scheduleGeocode()
    await vi.advanceTimersByTimeAsync(800)
    expect(geo.isGeocoding.value).toBe(true)

    resolve({ latitude: 1, longitude: 2 })
    await vi.advanceTimersByTimeAsync(0)
    expect(geo.isGeocoding.value).toBe(false)
  })

  it('does not touch existing coordinates until an address edit is scheduled', async () => {
    const { fetcher, latitude } = setup({ lat: 10, lng: 20 })

    await vi.advanceTimersByTimeAsync(5000)

    expect(fetcher).not.toHaveBeenCalled()
    expect(latitude.value).toBe(10)
  })

  it('reset cancels pending work and clears the manual flag', async () => {
    const { fetcher, geo } = setup()
    fetcher.mockResolvedValue({ latitude: 1, longitude: 2 })
    geo.markManualEdit()
    geo.reset()
    expect(geo.manuallyEdited.value).toBe(false)

    geo.scheduleGeocode()
    geo.reset()
    await vi.advanceTimersByTimeAsync(2000)
    expect(fetcher).not.toHaveBeenCalled()
  })
})
