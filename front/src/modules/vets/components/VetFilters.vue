<script setup lang="ts">
import { SearchOutlined } from '@ant-design/icons-vue'
import FiltersRow from '@/components/filters/FiltersRow.vue'
import FiltersCol from '@/components/filters/FiltersCol.vue'
import FiltersWrapper from '@/components/filters/FiltersWrapper.vue'
import type { VetFilters } from '../types/vet.types'

const props = defineProps<{ filters: VetFilters }>()
const emit = defineEmits<{ 'update:filters': [filters: VetFilters] }>()

const VALIDATED_OPTIONS = [
  { label: 'Validadas', value: true },
  { label: 'Pendientes', value: false },
]

const SUSPENDED_OPTIONS = [
  { label: 'Activas', value: false },
  { label: 'Suspendidas', value: true },
]
</script>

<template>
  <FiltersRow>
    <FiltersCol>
      <FiltersWrapper label="Buscar">
        <a-input
          :value="filters.search"
          placeholder="Nombre de veterinaria"
          allow-clear
          @update:value="(v: string) => emit('update:filters', { ...filters, search: v, page: 1 })"
        >
          <template #prefix>
            <SearchOutlined :style="{ color: 'var(--dt-muted, #6B8CAE)' }" />
          </template>
        </a-input>
      </FiltersWrapper>
    </FiltersCol>

    <FiltersCol>
      <FiltersWrapper label="Validación">
        <a-select
          :value="filters.validated"
          style="width: 100%"
          placeholder="Todas"
          allow-clear
          @update:value="(v: boolean | undefined) => emit('update:filters', { ...filters, validated: v ?? '', page: 1 })"
        >
          <a-select-option
            v-for="opt in VALIDATED_OPTIONS"
            :key="String(opt.value)"
            :value="opt.value"
          >
            {{ opt.label }}
          </a-select-option>
        </a-select>
      </FiltersWrapper>
    </FiltersCol>

    <FiltersCol>
      <FiltersWrapper label="Estado">
        <a-select
          :value="filters.suspended"
          style="width: 100%"
          placeholder="Todas"
          allow-clear
          @update:value="(v: boolean | undefined) => emit('update:filters', { ...filters, suspended: v ?? '', page: 1 })"
        >
          <a-select-option
            v-for="opt in SUSPENDED_OPTIONS"
            :key="String(opt.value)"
            :value="opt.value"
          >
            {{ opt.label }}
          </a-select-option>
        </a-select>
      </FiltersWrapper>
    </FiltersCol>
  </FiltersRow>
</template>
