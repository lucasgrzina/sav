<script setup lang="ts">
import { SearchOutlined } from '@ant-design/icons-vue'
import FiltersRow from '@/components/filters/FiltersRow.vue'
import FiltersCol from '@/components/filters/FiltersCol.vue'
import FiltersWrapper from '@/components/filters/FiltersWrapper.vue'
import type { RoleFilters } from '../types/role.types'

const props = defineProps<{ filters: RoleFilters }>()
const emit = defineEmits<{ 'update:filters': [filters: RoleFilters] }>()
</script>

<template>
  <FiltersRow>
    <FiltersCol>
      <FiltersWrapper label="Buscar">
        <a-input
          :value="filters.search"
          placeholder="Nombre del rol"
          allow-clear
          @update:value="(v: string) => emit('update:filters', { ...filters, search: v, page: 1 })"
        >
          <template #prefix>
            <SearchOutlined :style="{ color: 'var(--dt-muted, #6B8CAE)' }" />
          </template>
        </a-input>
      </FiltersWrapper>
    </FiltersCol>
  </FiltersRow>
</template>
