<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { usePermission } from '@/core/composables/usePermissions'
import AsyncContent from '@/components/shared/AsyncContent.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import HomeUpcomingPrograms from '../components/HomeUpcomingPrograms.vue'
import HomeRecentAlerts from '../components/HomeRecentAlerts.vue'
import HomeProblemAlerts from '../components/HomeProblemAlerts.vue'
import { useVetHome } from '../composables/useVetHome'
import { HOME_COPY } from '../constants/home-copy'

const route = useRoute()
const { user } = useAuth()
const { can } = usePermission()

const vetGuid = computed(() => route.params.vetGuid as string)
const canRead = computed(() => can('programs.read'))
const { data, isLoading, isFetching, error, refetch } = useVetHome(vetGuid)

const hasProblems = computed(() => (data.value?.problem_alerts?.length ?? 0) > 0)

function retry() {
  void refetch()
}
</script>

<template>
  <div class="flex flex-col gap-4" data-testid="vet-home">
    <AppHeader :title="HOME_COPY.greeting(user?.first_name)" :subtitle="HOME_COPY.subtitle">
      <template v-if="canRead" #actions="{ buttonSize }">
        <BaseButton :size="buttonSize" :loading="isFetching" data-testid="home-refresh" @click="retry">
          {{ HOME_COPY.refresh }}
        </BaseButton>
      </template>
    </AppHeader>

    <div v-if="!canRead" data-testid="home-no-access">
      <p class="text-gray-500">{{ HOME_COPY.noAccess }}</p>
      <RouterLink :to="`/vets/${vetGuid}/perfil`">{{ HOME_COPY.goToProfile }}</RouterLink>
    </div>

    <AsyncContent v-else :loading="isLoading" :error="error" :error-message="HOME_COPY.errorMessage" @retry="retry">
      <div v-if="data" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <HomeProblemAlerts
          v-if="data.problem_alerts"
          :vet-guid="vetGuid"
          :alerts="data.problem_alerts"
          :class="{ 'order-first': hasProblems }"
        />
        <HomeUpcomingPrograms :vet-guid="vetGuid" :programs="data.upcoming_programs" />
        <HomeRecentAlerts v-if="data.recent_alerts" :vet-guid="vetGuid" :alerts="data.recent_alerts" />
      </div>
    </AsyncContent>
  </div>
</template>
