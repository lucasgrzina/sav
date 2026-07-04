<script setup lang="ts">
import { SearchOutlined } from '@ant-design/icons-vue'
import FiltersRow from '@/components/filters/FiltersRow.vue'
import FiltersCol from '@/components/filters/FiltersCol.vue'
import FiltersWrapper from '@/components/filters/FiltersWrapper.vue'
import type { ClientFilters } from '../types/client.types'

const props = defineProps<{ filters: ClientFilters }>()
const emit = defineEmits<{ 'update:filters': [filters: ClientFilters] }>()
</script>

<template>
  <FiltersRow>
    <FiltersCol>
      <FiltersWrapper label="Buscar">
        <a-input
          :value="props.filters.search"
          placeholder="Buscar por nombre o CUIT..."
          allow-clear
          @update:value="(v: string) => emit('update:filters', { ...props.filters, search: v, page: 1 })"
        >
          <template #prefix>
            <SearchOutlined :style="{ color: 'var(--dt-muted, #6B8CAE)' }" />
          </template>
        </a-input>
      </FiltersWrapper>
    </FiltersCol>
  </FiltersRow>
</template>
