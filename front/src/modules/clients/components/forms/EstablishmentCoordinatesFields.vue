<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue'
import type { MapPoint } from '@/components/atoms/maps/BaseMapPicker.vue'

const BaseMapPicker = defineAsyncComponent(() => import('@/components/atoms/maps/BaseMapPicker.vue'))

withDefaults(defineProps<{
  isGeocoding?: boolean
  notFound?: boolean
  latitudeError?: string
  longitudeError?: string
}>(), {
  isGeocoding: false,
  notFound: false,
  latitudeError: '',
  longitudeError: '',
})

const emit = defineEmits<{
  'manual-edit': []
  recalculate: []
}>()

const latitude = defineModel<number | null | undefined>('latitude')
const longitude = defineModel<number | null | undefined>('longitude')

const point = computed<MapPoint>({
  get: () => ({ latitude: latitude.value, longitude: longitude.value }),
  set: (value) => {
    latitude.value = value.latitude
    longitude.value = value.longitude
    // A point picked on the map counts as a manual edit: auto-geocoding must not overwrite it
    emit('manual-edit')
  },
})
</script>

<template>
  <div class="coordinates-fields">
    <a-row :gutter="[16, 0]">
      <a-col :xs="24" :md="12">
        <a-form-item
          label="Latitud"
          :validate-status="latitudeError ? 'error' : ''"
          :help="latitudeError"
        >
          <a-input-number
            v-model:value="latitude"
            class="w-full"
            :step="0.000001"
            :min="-90"
            :max="90"
            placeholder="Ej: -35.123456"
            data-testid="latitude-input"
            @change="emit('manual-edit')"
          />
        </a-form-item>
      </a-col>

      <a-col :xs="24" :md="12">
        <a-form-item
          label="Longitud"
          :validate-status="longitudeError ? 'error' : ''"
          :help="longitudeError"
        >
          <a-input-number
            v-model:value="longitude"
            class="w-full"
            :step="0.000001"
            :min="-180"
            :max="180"
            placeholder="Ej: -62.123456"
            data-testid="longitude-input"
            @change="emit('manual-edit')"
          />
        </a-form-item>
      </a-col>
    </a-row>

    <BaseMapPicker v-model="point" class="mb-2" aria-label="Mapa: hacé clic para marcar la ubicación del establecimiento" />
    <p class="text-xs text-gray-500 mb-2">
      Hacé clic en el mapa o arrastrá el marcador para ajustar la ubicación.
    </p>

    <div class="coordinates-fields__status text-xs text-gray-500 mb-4">
      <span v-if="isGeocoding" data-testid="geocoding-loading">Buscando coordenadas...</span>
      <span v-else-if="notFound" data-testid="geocoding-not-found">
        No se encontraron coordenadas para esta dirección; podés cargarlas manualmente.
      </span>
      <a-button
        type="link"
        size="small"
        class="!px-0"
        :disabled="isGeocoding"
        data-testid="recalculate-coordinates"
        @click="emit('recalculate')"
      >
        Recalcular desde la dirección
      </a-button>
    </div>
  </div>
</template>
