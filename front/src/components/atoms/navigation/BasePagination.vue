<script setup lang="ts">
import { ref, watch } from 'vue'

const props = withDefaults(defineProps<{
  page?: number
  total?: number
  perPage?: number
  showSizeChanger?: boolean
  pageSizeOptions?: string[]
}>(), {
  page: 1,
  total: 0,
  perPage: 10,
  showSizeChanger: true,
  pageSizeOptions: () => ['10', '25', '50', '100'],
})

const emit = defineEmits<{ change: [{ page: number; perPage: number }] }>()

const internalPerPage = ref(props.perPage)
watch(() => props.perPage, (val) => { internalPerPage.value = val })

function onSizeChange(_: number, size: number) {
  internalPerPage.value = size
  emit('change', { page: 1, perPage: size })
}

function onPageChange(page: number) {
  emit('change', { page, perPage: internalPerPage.value })
}
</script>

<template>
  <div class="flex justify-end mt-4">
    <a-pagination
      :current="page"
      :total="total"
      :page-size="internalPerPage"
      :show-size-changer="showSizeChanger"
      :page-size-options="pageSizeOptions"
      show-quick-jumper
      @change="onPageChange"
      @show-size-change="onSizeChange"
    />
  </div>
</template>
