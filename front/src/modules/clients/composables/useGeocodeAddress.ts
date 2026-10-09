import { getCurrentScope, onScopeDispose, ref, type Ref } from 'vue'
import type { GeocodeAddressPayload, GeocodeResult } from '../types/client.types'

export type GeocodeFetcher = (payload: GeocodeAddressPayload, signal: AbortSignal) => Promise<GeocodeResult>

type MaybeText = Ref<string | null | undefined>
type MaybeNumber = Ref<number | null | undefined>

export interface UseGeocodeAddressOptions {
  address: MaybeText
  city: MaybeText
  state: MaybeText
  zipCode: MaybeText
  latitude: MaybeNumber
  longitude: MaybeNumber
  fetcher: GeocodeFetcher
  debounceMs?: number
}

const COORDINATE_DECIMALS = 6

function round(value: number): number {
  const factor = 10 ** COORDINATE_DECIMALS
  return Math.round(value * factor) / factor
}

function hasText(value: string | null | undefined): boolean {
  return (value ?? '').trim() !== ''
}

/**
 * Fills latitude/longitude from the address fields.
 *
 * It is driven by explicit events (not by watching the fields) so programmatic changes such as
 * loading an existing establishment never trigger a lookup:
 * - call `scheduleGeocode()` when the user edits an address field (debounced);
 * - call `markManualEdit()` when the user edits latitude/longitude (stops automatic overwrites);
 * - call `recalculate()` to clear the manual flag and geocode right away.
 */
export function useGeocodeAddress(options: UseGeocodeAddressOptions) {
  const { address, city, state, zipCode, latitude, longitude, fetcher } = options
  const debounceMs = options.debounceMs ?? 800

  const isGeocoding = ref(false)
  const notFound = ref(false)
  const manuallyEdited = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null
  let controller: AbortController | null = null
  let requestId = 0

  // Invalidates the pending timer and any in-flight request (its response will be ignored).
  function cancelPending(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
    controller?.abort()
    controller = null
    requestId += 1
    isGeocoding.value = false
  }

  async function run(): Promise<void> {
    if (!hasText(address.value) && !hasText(city.value)) {
      notFound.value = false
      return
    }

    cancelPending()
    const currentId = requestId
    const currentController = new AbortController()
    controller = currentController
    isGeocoding.value = true
    notFound.value = false

    try {
      const result = await fetcher(
        {
          address: address.value || null,
          city: city.value || null,
          state: state.value || null,
          zip_code: zipCode.value || null,
        },
        currentController.signal,
      )
      if (currentId !== requestId) return // stale or out-of-order response

      if (result.latitude !== null && result.longitude !== null) {
        latitude.value = round(result.latitude)
        longitude.value = round(result.longitude)
      } else {
        notFound.value = true
      }
    } catch {
      if (currentId !== requestId) return
      notFound.value = true
    } finally {
      if (currentId === requestId) {
        isGeocoding.value = false
        controller = null
      }
    }
  }

  function scheduleGeocode(): void {
    cancelPending()
    if (manuallyEdited.value) return

    if (!hasText(address.value) && !hasText(city.value)) {
      notFound.value = false
      return
    }

    timer = setTimeout(() => {
      timer = null
      void run()
    }, debounceMs)
  }

  function markManualEdit(): void {
    manuallyEdited.value = true
    notFound.value = false
    cancelPending()
  }

  async function recalculate(): Promise<void> {
    manuallyEdited.value = false
    await run()
  }

  // Back to the initial state (e.g. when the modal is opened/closed).
  function reset(): void {
    cancelPending()
    manuallyEdited.value = false
    notFound.value = false
  }

  if (getCurrentScope()) onScopeDispose(cancelPending)

  return { isGeocoding, notFound, manuallyEdited, scheduleGeocode, markManualEdit, recalculate, reset }
}
