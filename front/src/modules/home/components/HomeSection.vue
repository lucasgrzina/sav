<script setup lang="ts">
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import BaseEmptyState from '@/components/atoms/feedback/BaseEmptyState.vue'

defineProps<{
  title: string
  empty: boolean
  emptyText: string
  count?: number
}>()
</script>

<template>
  <BaseCard>
    <template #header>
      <span class="flex items-center gap-2">
        {{ title }}
        <a-badge v-if="count" :count="count" :overflow-count="99" data-testid="section-count" />
      </span>
    </template>
    <BaseEmptyState v-if="empty" :description="emptyText" data-testid="section-empty" />
    <ul v-else class="m-0 flex list-none flex-col gap-2 p-0">
      <slot />
    </ul>
  </BaseCard>
</template>
