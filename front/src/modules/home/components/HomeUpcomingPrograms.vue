<script setup lang="ts">
import { parseDate } from '@/core/utils/date'
import HomeSection from './HomeSection.vue'
import { HOME_COPY } from '../constants/home-copy'
import type { HomeUpcomingProgram } from '../types/home.types'

defineProps<{ vetGuid: string; programs: HomeUpcomingProgram[]; hasMore?: boolean }>()

function dateLabel(date: string): string {
  return parseDate(date).toLocaleDateString('es-AR', { weekday: 'short', day: '2-digit', month: '2-digit' })
}
</script>

<template>
  <HomeSection
    :title="HOME_COPY.upcoming.title"
    :empty="programs.length === 0"
    :empty-text="HOME_COPY.upcoming.empty"
    :more-text="hasMore ? HOME_COPY.upcoming.more : undefined"
    :more-label="HOME_COPY.upcoming.seeAll"
    :more-to="{ name: 'vet-programs-list', params: { vetGuid } }"
    data-testid="home-upcoming"
  >
    <li v-for="program in programs" :key="program.guid">
      <RouterLink
        :to="{ name: 'vet-programs-detail', params: { vetGuid, guid: program.guid } }"
        class="flex min-h-[44px] items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-3 text-inherit no-underline"
        data-testid="upcoming-item"
      >
        <span class="min-w-0 break-words">
          <span class="block font-semibold">{{ program.client.name }}</span>
          <span class="block text-gray-500">{{ program.establishment.name }}</span>
          <span class="block text-gray-500">{{ program.protocol.name }} · {{ program.technique.name }}</span>
        </span>
        <a-tag color="blue" class="m-0 shrink-0">{{ dateLabel(program.next_target_date) }}</a-tag>
      </RouterLink>
    </li>
  </HomeSection>
</template>
