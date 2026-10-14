<script setup lang="ts">
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import { PREVIEW_COPY } from '../../../constants/alert-copy'

defineProps<{ message: string | null }>()
const open = defineModel<boolean>({ default: false })
</script>

<template>
  <BaseModal v-model="open" :title="PREVIEW_COPY.messageTitle">
    <p class="pmm-note">{{ PREVIEW_COPY.messageNote }}</p>
    <pre class="pmm-message" data-testid="rendered-message">{{ message }}</pre>
  </BaseModal>
</template>

<style scoped>
.pmm-note {
  margin: 0 0 8px;
  color: #6b7280;
}

.pmm-message {
  margin: 0;
  padding: 12px;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: inherit;
  background: #f5f5f5;
  border-radius: 6px;
}
</style>
