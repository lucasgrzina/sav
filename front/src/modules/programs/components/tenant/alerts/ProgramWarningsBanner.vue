<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import { ACTIONS_COPY } from '../../../constants/alert-copy'
import type { ProgramWarning } from '../../../types/program-alerts.types'
import ProgramWarningList from './ProgramWarningList.vue'

const props = defineProps<{ warnings: ProgramWarning[] }>()

// DEC-09: in tab mode the banner only counts critical warnings (those that mean "will not be sent").
const critical = computed(() => props.warnings.filter((w) => w.severity === 'critical'))
const total = computed(() =>
  critical.value.reduce((sum, w) => {
    const count = w.context.count
    return sum + (typeof count === 'number' && count > 0 ? count : 1)
  }, 0),
)

const open = ref(false)
</script>

<template>
  <a-alert v-if="critical.length" type="error" show-icon :message="ACTIONS_COPY.warningsBanner(total)" data-testid="warnings-banner">
    <template #action>
      <BaseButton size="small" variant="secondary" data-testid="warnings-detail" @click="open = true">
        {{ ACTIONS_COPY.warningsDetail }}
      </BaseButton>
    </template>
  </a-alert>
  <BaseDrawer v-model="open" :title="ACTIONS_COPY.warningsDrawerTitle">
    <ProgramWarningList :warnings="warnings" />
  </BaseDrawer>
</template>
