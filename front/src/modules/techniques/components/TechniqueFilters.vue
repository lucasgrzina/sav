<script setup lang="ts">
import { SearchOutlined } from '@ant-design/icons-vue'
import FiltersRow from '@/components/filters/FiltersRow.vue'
import FiltersCol from '@/components/filters/FiltersCol.vue'
import FiltersWrapper from '@/components/filters/FiltersWrapper.vue'
import type { TechniqueListParams, TechniqueType } from '../types/technique.types'

const props = defineProps<{ filters: TechniqueListParams }>()
const emit = defineEmits<{ 'update:filters': [filters: TechniqueListParams] }>()

const typeOptions = [
  { label: 'Todos', value: undefined },
  { label: 'Técnicas', value: 'technique' as TechniqueType },
  { label: 'Vacunas', value: 'vaccine' as TechniqueType },
]
</script>

<template>
  <FiltersRow>
    <FiltersCol>
      <FiltersWrapper label="Buscar">
        <a-input
          :value="filters.search"
          placeholder="Buscar por nombre..."
          allow-clear
          @update:value="(v: string) => emit('update:filters', { ...filters, search: v || undefined, page: 1 })"
        >
          <template #prefix>
            <SearchOutlined :style="{ color: 'var(--dt-muted, #6B8CAE)' }" />
          </template>
        </a-input>
      </FiltersWrapper>
    </FiltersCol>

    <FiltersCol>
      <FiltersWrapper label="Tipo">
        <a-select
          :value="filters.type"
          placeholder="Todos los tipos"
          allow-clear
          style="width: 100%"
          @change="(v: TechniqueType | undefined) => emit('update:filters', { ...filters, type: v, page: 1 })"
        >
          <a-select-option
            v-for="opt in typeOptions"
            :key="opt.value ?? 'all'"
            :value="opt.value"
          >
            {{ opt.label }}
          </a-select-option>
        </a-select>
      </FiltersWrapper>
    </FiltersCol>
  </FiltersRow>
</template>
