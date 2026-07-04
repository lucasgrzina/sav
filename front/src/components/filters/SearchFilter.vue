<script setup lang="ts">
import { computed } from 'vue'
import { SearchOutlined } from '@ant-design/icons-vue'

interface Props {
  modelValue?: string
  placeholder?: string
  loading?: boolean
  disabled?: boolean
  size?: 'small' | 'middle' | 'large'
  allowClear?: boolean
}

const props = withDefaults(
  defineProps<Props>(),
  {
    modelValue: '',
    placeholder: 'Buscar...',
    loading: false,
    disabled: false,
    size: 'middle',
    allowClear: true
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'search', value: string): void
  (e: 'clear'): void
}>()

const model = computed({
  get: () => props.modelValue,
  set: (value: string) => { emit('update:modelValue', value) }
})

function handleSearch(value: string) {
  emit('search', value)
}

function handleClear() {
  emit('clear')
  emit('update:modelValue', '')
}
</script>

<template>
  <div class="search-filter">
    <a-input
      v-model="model"
      :placeholder="placeholder"
      :loading="loading"
      :disabled="disabled"
      :size="size"
      :allow-clear="allowClear"
      @press-enter="handleSearch(model)"
      @search="handleSearch"
      @clear="handleClear"
    >
      <template #prefix>
        <SearchOutlined />
      </template>
    </a-input>
  </div>
</template>

<style scoped>
.search-filter {
  @apply w-full;
}
</style>
