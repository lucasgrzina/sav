<script setup lang="ts">
import { CANCEL_REASON_COPY, PREVIEW_COPY } from '../../../constants/alert-copy'
import type { PreviewDiff } from '../../../types/program-alerts.types'

defineProps<{ diff: PreviewDiff }>()
</script>

<template>
  <section class="ppd-root" data-testid="preview-diff">
    <h4 class="ppd-title">{{ PREVIEW_COPY.diffTitle }}</h4>
    <p data-testid="diff-counts">{{ PREVIEW_COPY.diffCounts(diff.kept, diff.updated, diff.created, diff.cancelled) }}</p>
    <p v-if="diff.protocol_changed">{{ PREVIEW_COPY.diffProtocolChanged }}</p>
    <p v-if="diff.sent_not_modified.length">
      {{ PREVIEW_COPY.diffSentNotModified(diff.sent_not_modified.length) }}
    </p>
    <ul v-if="diff.rescheduled.length" class="ppd-list">
      <li v-for="(r, i) in diff.rescheduled" :key="`r-${i}`">
        {{ r.task_description ?? '' }}: {{ r.from.label }} → {{ r.to.label }}
      </li>
    </ul>
    <ul v-if="diff.cancelled_items.length" class="ppd-list">
      <li v-for="(c, i) in diff.cancelled_items" :key="`c-${i}`">
        {{ c.task_description ?? '' }} ({{ c.scheduled_at_local.label }}): {{ CANCEL_REASON_COPY[c.reason] }}
      </li>
    </ul>
  </section>
</template>

<style scoped>
.ppd-root {
  margin-bottom: 16px;
  padding: 12px;
  background: #fafafa;
  border-radius: 6px;
}

.ppd-title {
  margin: 0 0 8px;
}

.ppd-list {
  margin: 4px 0 0;
  padding-left: 18px;
}

p {
  margin: 0 0 4px;
}
</style>
