<script setup lang="ts">
import HomeSection from './HomeSection.vue'
import ProgramAlertStatusTag from '@/modules/programs/components/tenant/alerts/ProgramAlertStatusTag.vue'
import { HOME_COPY } from '../constants/home-copy'
import { ALERT_TYPE_LABEL, FAILURE_COPY } from '@/modules/programs/constants/alert-copy'
import type { HomeProblemAlertItem } from '../types/home.types'

defineProps<{ vetGuid: string; alerts: HomeProblemAlertItem[] }>()
</script>

<template>
  <HomeSection
    :title="HOME_COPY.problems.title"
    :count="alerts.length"
    :empty="alerts.length === 0"
    :empty-text="HOME_COPY.problems.empty"
    data-testid="home-problems"
  >
    <li v-for="alert in alerts" :key="alert.key">
      <RouterLink
        :to="{ name: 'vet-programs-detail', params: { vetGuid, guid: alert.program.guid }, query: { tab: 'alerts' } }"
        class="block min-h-[44px] rounded-lg border border-red-200 bg-white p-3 text-inherit no-underline"
        data-testid="problem-item"
      >
        <span class="flex items-start justify-between gap-2">
          <span class="min-w-0 break-words font-semibold">{{ alert.recipient.name }}</span>
          <ProgramAlertStatusTag :state="alert.state" class="m-0 shrink-0" />
        </span>
        <span class="block break-words">{{ alert.task_description ?? ALERT_TYPE_LABEL[alert.type] ?? HOME_COPY.recent.noTask }}</span>
        <span class="block break-words text-gray-500">{{ alert.program.client.name }} · {{ alert.program.establishment.name }}</span>
        <span class="block text-gray-500">{{ HOME_COPY.problems.scheduledFor(alert.scheduled_at_local.label) }}</span>
        <span class="mt-1 block break-words text-red-700" data-testid="problem-reason">{{ FAILURE_COPY[alert.failure_code]?.text ?? FAILURE_COPY.unknown.text }}</span>
        <span class="mt-1 block text-blue-600">{{ HOME_COPY.problems.open }}</span>
      </RouterLink>
    </li>
  </HomeSection>
</template>
