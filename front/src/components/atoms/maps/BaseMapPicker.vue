<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Map as LeafletMap, Marker } from 'leaflet'
import {
  MAP_DEFAULT_CENTER,
  MAP_DEFAULT_ZOOM,
  MAP_POINT_ZOOM,
  MAP_TILE_ATTRIBUTION,
  MAP_TILE_MAX_ZOOM,
  MAP_TILE_URL,
} from '@/config/map'

export interface MapPoint {
  latitude: number | null | undefined
  longitude: number | null | undefined
}

const props = withDefaults(defineProps<{
  height?: string
  ariaLabel?: string
}>(), {
  height: '260px',
  ariaLabel: 'Mapa para seleccionar la ubicación',
})

const model = defineModel<MapPoint>({ default: () => ({ latitude: null, longitude: null }) })

const COORDINATE_DECIMALS = 6

function round(value: number): number {
  const factor = 10 ** COORDINATE_DECIMALS
  return Math.round(value * factor) / factor
}

function hasPoint(point: MapPoint): point is { latitude: number; longitude: number } {
  return typeof point.latitude === 'number' && typeof point.longitude === 'number'
}

const container = ref<HTMLDivElement | null>(null)
const isReady = ref(false)

let map: LeafletMap | null = null
let marker: Marker | null = null
let leaflet: typeof import('leaflet') | null = null
let resizeObserver: ResizeObserver | null = null
let disposed = false

let lastEmitted: { latitude: number; longitude: number } | null = null

function emitPoint(lat: number, lng: number): void {
  lastEmitted = { latitude: round(lat), longitude: round(lng) }
  model.value = { ...lastEmitted }
}

function syncFromModel(recenter: boolean): void {
  if (!map || !leaflet) return
  const point = model.value
  if (!hasPoint(point)) {
    marker?.remove()
    marker = null
    return
  }

  const latlng = leaflet.latLng(point.latitude, point.longitude)
  if (marker) {
    const current = marker.getLatLng()
    if (round(current.lat) === point.latitude && round(current.lng) === point.longitude && !recenter) return
    marker.setLatLng(latlng)
  } else {
    marker = createMarker(latlng)
  }
  if (recenter) map.setView(latlng, Math.max(map.getZoom(), MAP_POINT_ZOOM))
  else if (!map.getBounds().contains(latlng)) map.panTo(latlng)
}

function createMarker(latlng: import('leaflet').LatLng): Marker {
  const created = leaflet!.marker(latlng, { draggable: true, keyboard: true }).addTo(map!)
  created.on('dragend', () => {
    const { lat, lng } = created.getLatLng()
    emitPoint(lat, lng)
  })
  return created
}

onMounted(async () => {
  const [L, { default: iconUrl }, { default: iconRetinaUrl }, { default: shadowUrl }] = await Promise.all([
    import('leaflet'),
    import('leaflet/dist/images/marker-icon.png'),
    import('leaflet/dist/images/marker-icon-2x.png'),
    import('leaflet/dist/images/marker-shadow.png'),
    import('leaflet/dist/leaflet.css'),
  ])
  if (disposed || !container.value) return
  leaflet = L

  // Vite does not resolve Leaflet's default icon paths: set the bundled assets explicitly.
  L.Marker.prototype.options.icon = L.icon({
    iconUrl,
    iconRetinaUrl,
    shadowUrl,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41],
  })

  const point = model.value
  map = L.map(container.value).setView(
    hasPoint(point) ? [point.latitude, point.longitude] : MAP_DEFAULT_CENTER,
    hasPoint(point) ? MAP_POINT_ZOOM : MAP_DEFAULT_ZOOM,
  )
  L.tileLayer(MAP_TILE_URL, { attribution: MAP_TILE_ATTRIBUTION, maxZoom: MAP_TILE_MAX_ZOOM }).addTo(map)

  map.on('click', (event) => emitPoint(event.latlng.lat, event.latlng.lng))
  syncFromModel(false)
  isReady.value = true

  // The map is usually mounted inside an animating modal: recompute its size whenever the box changes.
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => map?.invalidateSize())
    resizeObserver.observe(container.value)
  }
  setTimeout(() => map?.invalidateSize(), 300)
})

// External changes (geocoding, manual typing, loading an establishment) move the marker and recenter.
watch(() => [model.value.latitude, model.value.longitude], ([lat, lng]) => {
  // Changes that came from the map itself must not recenter/zoom under the user's cursor.
  const fromMap = lastEmitted !== null && lastEmitted.latitude === lat && lastEmitted.longitude === lng
  syncFromModel(!fromMap)
})

onBeforeUnmount(() => {
  disposed = true
  resizeObserver?.disconnect()
  resizeObserver = null
  map?.remove()
  map = null
  marker = null
})

defineExpose({ invalidateSize: () => map?.invalidateSize() })
</script>

<template>
  <div
    class="relative w-full overflow-hidden rounded-md border border-gray-200"
    :style="{ height: props.height }"
  >
    <div
      ref="container"
      class="h-full w-full"
      role="application"
      :aria-label="props.ariaLabel"
      data-testid="map-picker"
    />
    <div
      v-if="!isReady"
      class="absolute inset-0 flex items-center justify-center bg-gray-50 text-xs text-gray-500"
      data-testid="map-picker-loading"
    >
      Cargando mapa...
    </div>
  </div>
</template>
