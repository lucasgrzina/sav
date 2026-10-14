<script setup lang="ts">
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { PREVIEW_COPY } from '../../../constants/alert-copy'

defineProps<{ saving?: boolean }>()
defineEmits<{ retry: []; saveWithoutSummary: [] }>()
</script>

<template>
  <a-alert type="error" show-icon :message="PREVIEW_COPY.errorTitle" :description="PREVIEW_COPY.errorDetail" data-testid="preview-error">
    <template #action>
      <div class="ppe-actions">
        <BaseButton size="small" variant="secondary" data-testid="preview-retry" @click="$emit('retry')">
          {{ PREVIEW_COPY.retry }}
        </BaseButton>
        <BaseButton size="small" :loading="saving" data-testid="preview-save-without" @click="$emit('saveWithoutSummary')">
          {{ PREVIEW_COPY.saveWithoutSummary }}
        </BaseButton>
      </div>
    </template>
  </a-alert>
</template>

<style scoped>
.ppe-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
</style>
