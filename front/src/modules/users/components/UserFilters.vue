<script setup lang="ts">
import { computed } from 'vue'
import { SearchOutlined } from '@ant-design/icons-vue'
import { USER_STATUS_OPTIONS } from '../constants/users.constants'
import type { UserFilters } from '../types/user.types'
import FiltersRow from '@/components/filters/FiltersRow.vue';
import FiltersCol from '@/components/filters/FiltersCol.vue';
import FiltersWrapper from '@/components/filters/FiltersWrapper.vue';

const props = defineProps<{ filters: UserFilters }>()
const emit = defineEmits<{ 'update:filters': [filters: UserFilters] }>()

const dateRangeValue = computed<[string, string] | null>(() => {
  if (props.filters.date_from && props.filters.date_to) {
    return [props.filters.date_from, props.filters.date_to]
  }
  return null
})

function handleDateChange(val: [string, string] | null) {
  emit('update:filters', {
    ...props.filters,
    date_from: val ? val[0] : '',
    date_to: val ? val[1] : '',
    page: 1,
  })
}
</script>

<template>
  <FiltersRow>
    <FiltersCol>
      <FiltersWrapper label="Buscar">
        <a-input
          :value="filters.search"
          placeholder="Nombre o email"
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
      <FiltersWrapper label="Estado">
        <a-select
          :value="filters.status"
          style="width: 100%"
          placeholder="Todos los estados"
          allow-clear
          @update:value="(v: string | null) => emit('update:filters', { ...filters, status: (v ?? null) as UserFilters['status'], page: 1 })"
        >
          <a-select-option
            v-for="opt in USER_STATUS_OPTIONS"
            :key="opt.value"
            :value="opt.value"
          >
            {{ opt.label }}
          </a-select-option>
        </a-select>  
      </FiltersWrapper>    
    </FiltersCol>

    <FiltersCol>
      <FiltersWrapper label="Fecha de registro">
        <a-range-picker
          :value="dateRangeValue"
          style="width: 100%"
          format="YYYY-MM-DD"
          value-format="YYYY-MM-DD"
          :placeholder="['Desde', 'Hasta']"
          @update:value="handleDateChange"
        />         
      </FiltersWrapper>
 
    </FiltersCol>
  </FiltersRow>

</template>

<style scoped>

.filter-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--dt-muted, #6b8cae);
}
</style>
