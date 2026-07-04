<script setup lang="ts">
import {
  Empty as AEmpty
} from 'ant-design-vue'

interface Props {
  title?: string
}

withDefaults(
  defineProps<Props>(),
  {
    title: 'Sin resultados'
  }
)
</script>

<template>
  <div class="base-table-empty">
    <AEmpty :description="title">
      <template
        v-if="$slots.extra"
        #default
      >
        <slot name="extra" />
      </template>
    </AEmpty>
  </div>
</template>

<style scoped>
.base-table-empty {
  @apply py-8;
}
</style>