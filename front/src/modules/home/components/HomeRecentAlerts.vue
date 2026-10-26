<script setup lang="ts">
import HomeSection from './HomeSection.vue'
import ProgramAlertStatusTag from '@/modules/programs/components/tenant/alerts/ProgramAlertStatusTag.vue'
import { HOME_COPY } from '../constants/home-copy'
import { ALERT_TYPE_LABEL } from '@/modules/programs/constants/alert-copy'
import type { HomeAlertItem } from '../types/home.types'

defineProps<{ vetGuid: string; alerts: HomeAlertItem[]; hasMore?: boolean | null }>()
</script>

<template>
  <HomeSection
    :title="HOME_COPY.recent.title"
    :empty="alerts.length === 0"
    :empty-text="HOME_COPY.recent.empty"
    :more-text="hasMore ? HOME_COPY.recent.more : undefined"
    :more-label="HOME_COPY.upcoming.seeAll"
    :more-to="{ name: 'vet-programs-list', params: { vetGuid } }"
    data-testid="home-recent"
  >
    <li v-for="alert in alerts" :key="alert.key">
      <RouterLink
        :to="{ name: 'vet-programs-detail', params: { vetGuid, guid: alert.program.guid }, query: { tab: 'alerts' } }"
        class="block min-h-[44px] rounded-lg border border-gray-200 bg-white p-3 text-inherit no-underline"
        data-testid="recent-item"
      >
        <span class="flex items-start justify-between gap-2">
          <span class="min-w-0 break-words font-semibold">{{ alert.recipient.name }}</span>
          <ProgramAlertStatusTag :state="alert.state" class="m-0 shrink-0" />
        </span>
        <span class="block break-words">{{ alert.task_description ?? ALERT_TYPE_LABEL[alert.type] ?? HOME_COPY.recent.noTask }}</span>
        <span class="block break-words text-gray-500">{{ alert.program.client.name }} · {{ alert.program.establishment.name }}</span>
        <span v-if="alert.sent_at_local" class="block text-gray-500">{{ HOME_COPY.recent.sentAt(alert.sent_at_local.label) }}</span>
      </RouterLink>
    </li>
  </HomeSection>
</template>
