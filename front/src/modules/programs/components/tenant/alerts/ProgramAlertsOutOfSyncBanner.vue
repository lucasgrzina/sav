<script setup lang="ts">
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { ACTIONS_COPY } from '../../../constants/alert-copy'

defineProps<{ loading: boolean }>()
const emit = defineEmits<{ rebuild: [] }>()
</script>

<template>
  <a-alert type="warning" show-icon :message="ACTIONS_COPY.outOfSync" data-testid="out-of-sync-banner">
    <template #action>
      <PermissionGuard permission="programs.update">
        <BaseButton size="small" variant="secondary" :loading="loading" data-testid="rebuild-alerts" @click="emit('rebuild')">
          {{ ACTIONS_COPY.rebuild }}
        </BaseButton>
      </PermissionGuard>
    </template>
  </a-alert>
</template>
