<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import BaseEmptyState from '@/components/atoms/feedback/BaseEmptyState.vue'

defineProps<{
  title: string
  empty: boolean
  emptyText: string
  count?: number
  /** Shown as a muted note under the list when it was cut at its limit. */
  moreText?: string
  moreLabel?: string
  moreTo?: RouteLocationRaw
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
    <p v-if="moreText" class="m-0 mt-3 text-sm text-gray-500" data-testid="section-more">
      {{ moreText }}
      <RouterLink v-if="moreTo && moreLabel" :to="moreTo">{{ moreLabel }}</RouterLink>
    </p>
  </BaseCard>
</template>
