<script setup lang="ts">
import { computed } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { ACTIONS_COPY, FAILURE_COPY, RETRY_REASON_COPY } from '../../../constants/alert-copy'
import type { ProgramAlertLine, RetryBlockedReason } from '../../../types/program-alerts.types'

const props = defineProps<{
  line: ProgramAlertLine
  canGoToContacts: boolean
  retrying?: boolean
}>()
const emit = defineEmits<{ retry: [line: ProgramAlertLine]; contacts: [line: ProgramAlertLine] }>()

// Reasons that never get a disabled button: there is nothing the user can do about them.
const HIDDEN_REASONS: RetryBlockedReason[] = ['not_retryable', 'program_cancelled', 'alert_cancelled']

const showContacts = computed(() => {
  const code = props.line.failure_code
  return props.canGoToContacts
    && props.line.bucket === 'problem'
    && code !== null
    && FAILURE_COPY[code].action === 'go_to_contacts'
})

const retryAdvice = computed(() => props.line.retry)
const showRetry = computed(() => {
  const advice = retryAdvice.value
  if (!advice || !advice.recipient_guid) return false
  if (advice.allowed) return true
  return !HIDDEN_REASONS.includes(advice.blocked_reason as RetryBlockedReason)
})
const blockedText = computed(() => {
  const reason = retryAdvice.value?.blocked_reason as RetryBlockedReason | null | undefined
  return reason ? RETRY_REASON_COPY[reason] ?? null : null
})
</script>

<template>
  <div v-if="showRetry || showContacts" class="para-root" data-testid="alert-actions">
    <BaseButton
      v-if="showContacts"
      size="small"
      variant="tertiary"
      data-testid="alert-go-contacts"
      @click="emit('contacts', line)"
    >
      {{ ACTIONS_COPY.goToContacts }}
    </BaseButton>
    <PermissionGuard v-if="showRetry" permission="programs.update">
      <BaseButton
        size="small"
        variant="secondary"
        :disabled="!retryAdvice?.allowed"
        :loading="retrying"
        data-testid="alert-retry"
        @click="emit('retry', line)"
      >
        {{ ACTIONS_COPY.retry }}
      </BaseButton>
      <span v-if="!retryAdvice?.allowed && blockedText" class="para-reason" data-testid="alert-retry-reason">
        {{ blockedText }}
      </span>
    </PermissionGuard>
  </div>
</template>

<style scoped>
.para-root {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
}

.para-reason {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
</style>
