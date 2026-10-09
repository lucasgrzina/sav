import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const leafletMock = vi.hoisted(() => {
  const handlers: Record<string, (e: unknown) => void> = {}
  const markerHandlers: Record<string, () => void> = {}
  const markerObj = {
    addTo: vi.fn(() => markerObj),
    on: vi.fn((name: string, cb: () => void) => { markerHandlers[name] = cb; return markerObj }),
    setLatLng: vi.fn(),
    getLatLng: vi.fn(() => ({ lat: -34.5, lng: -58.4 })),
    remove: vi.fn(),
  }
  const mapObj = {
    setView: vi.fn(() => mapObj),
    on: vi.fn((name: string, cb: (e: unknown) => void) => { handlers[name] = cb; return mapObj }),
    remove: vi.fn(),
    invalidateSize: vi.fn(),
    getZoom: vi.fn(() => 4),
    getBounds: vi.fn(() => ({ contains: () => true })),
    panTo: vi.fn(),
  }
  const L = {
    map: vi.fn(() => mapObj),
    tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
    marker: vi.fn(() => markerObj),
    icon: vi.fn(() => ({})),
    latLng: vi.fn((lat: number, lng: number) => ({ lat, lng })),
    Marker: { prototype: { options: {} as Record<string, unknown> } },
  }
  return { L, mapObj, markerObj, handlers, markerHandlers }
})

vi.mock('leaflet', () => ({ default: leafletMock.L, ...leafletMock.L }))
vi.mock('leaflet/dist/leaflet.css', () => ({}))
vi.mock('leaflet/dist/images/marker-icon.png', () => ({ default: 'icon.png' }))
vi.mock('leaflet/dist/images/marker-icon-2x.png', () => ({ default: 'icon2x.png' }))
vi.mock('leaflet/dist/images/marker-shadow.png', () => ({ default: 'shadow.png' }))

import BaseMapPicker from './BaseMapPicker.vue'

function mountPicker(modelValue: { latitude: number | null; longitude: number | null }) {
  return mount(BaseMapPicker, {
    props: {
      modelValue,
      'onUpdate:modelValue': (v: unknown) => wrapperUpdate(v),
    },
  })
}
// Dynamic imports resolve asynchronously: wait until the map has been created
async function ready(): Promise<void> {
  await vi.waitFor(() => expect(leafletMock.L.map).toHaveBeenCalled())
  await flushPromises()
}
let wrapperUpdate: (v: unknown) => void

describe('BaseMapPicker', () => {
  let emitted: unknown[]

  beforeEach(() => {
    vi.clearAllMocks()
    emitted = []
    wrapperUpdate = (v) => emitted.push(v)
  })

  it('centers on Argentina without a marker when there are no coordinates', async () => {
    mountPicker({ latitude: null, longitude: null })
    await ready()
    expect(leafletMock.mapObj.setView).toHaveBeenCalledWith([-38.4161, -63.6167], 4)
    expect(leafletMock.L.marker).not.toHaveBeenCalled()
  })

  it('centers on the coordinates with a draggable marker when present', async () => {
    mountPicker({ latitude: -34.5, longitude: -58.4 })
    await ready()
    expect(leafletMock.mapObj.setView).toHaveBeenCalledWith([-34.5, -58.4], 15)
    expect(leafletMock.L.marker).toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ draggable: true }))
  })

  it('emits rounded coordinates when the map is clicked', async () => {
    mountPicker({ latitude: null, longitude: null })
    await ready()
    leafletMock.handlers.click({ latlng: { lat: -34.12345678, lng: -58.98765432 } })
    expect(emitted).toEqual([{ latitude: -34.123457, longitude: -58.987654 }])
  })

  it('emits the new position when the marker is dragged', async () => {
    mountPicker({ latitude: -34.5, longitude: -58.4 })
    await ready()
    leafletMock.markerHandlers.dragend()
    expect(emitted).toEqual([{ latitude: -34.5, longitude: -58.4 }])
  })

  it('moves the marker and recenters on external changes', async () => {
    const wrapper = mountPicker({ latitude: -34.5, longitude: -58.4 })
    await ready()
    await wrapper.setProps({ modelValue: { latitude: -31.4, longitude: -64.2 } })
    await nextTick()
    expect(leafletMock.markerObj.setLatLng).toHaveBeenCalledWith({ lat: -31.4, lng: -64.2 })
    expect(leafletMock.mapObj.setView).toHaveBeenLastCalledWith({ lat: -31.4, lng: -64.2 }, 15)
  })

  it('removes the map on unmount', async () => {
    const wrapper = mountPicker({ latitude: null, longitude: null })
    await ready()
    wrapper.unmount()
    expect(leafletMock.mapObj.remove).toHaveBeenCalled()
  })
})
