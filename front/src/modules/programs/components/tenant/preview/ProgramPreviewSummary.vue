<script setup lang="ts">
import { computed } from 'vue'
import { PREVIEW_COPY } from '../../../constants/alert-copy'
import type { ProgramPreview } from '../../../types/program-alerts.types'

const props = defineProps<{ preview: ProgramPreview }>()

// Window dates arrive as YYYY-MM-DD; shown as dd/mm/yyyy without timezone conversions.
function formatIsoDate(iso: string | null): string | null {
  if (!iso) return null
  const [y, m, d] = iso.slice(0, 10).split('-')
  return `${d}/${m}/${y}`
}

const sentence = computed(() => {
  const s = props.preview.summary
  return PREVIEW_COPY.summary(
    s.alerts_total,
    s.recipients_total,
    formatIsoDate(s.window.from),
    formatIsoDate(s.window.to),
    s.alerts_wont_send,
  )
})
</script>

<template>
  <section class="pps-root" data-testid="preview-summary">
    <dl class="pps-header">
      <div><dt>{{ PREVIEW_COPY.client }}</dt><dd>{{ preview.program.client.name }}</dd></div>
      <div><dt>{{ PREVIEW_COPY.establishment }}</dt><dd>{{ preview.program.establishment.name }}</dd></div>
      <div><dt>{{ PREVIEW_COPY.protocol }}</dt><dd>{{ preview.program.protocol.name }}</dd></div>
      <div><dt>{{ PREVIEW_COPY.technique }}</dt><dd>{{ preview.program.technique.name }}</dd></div>
      <div v-if="preview.program.managers.length">
        <dt>{{ PREVIEW_COPY.managers }}</dt>
        <dd>{{ preview.program.managers.map((m) => `${m.name} (${m.role})`).join(', ') }}</dd>
      </div>
    </dl>
    <p class="pps-sentence" data-testid="preview-sentence">{{ sentence }}</p>
    <a-alert v-if="preview.truncated" type="info" show-icon :message="PREVIEW_COPY.truncated" />
  </section>
</template>

<style scoped>
.pps-header {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 8px 16px;
  margin: 0 0 12px;
}

.pps-header dt {
  color: var(--pp-muted, #6b7280);
  font-size: 12px;
}

.pps-header dd {
  margin: 0;
  font-weight: 500;
}

.pps-sentence {
  margin: 0 0 12px;
  font-weight: 500;
}
</style>
