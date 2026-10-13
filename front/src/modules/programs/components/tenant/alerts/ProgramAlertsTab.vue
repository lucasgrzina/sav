<script setup lang="ts">
import { computed, watch } from 'vue'
import { storeToRefs } from 'pinia'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseEmptyState from '@/components/atoms/feedback/BaseEmptyState.vue'
import BaseErrorState from '@/components/atoms/feedback/BaseErrorState.vue'
import BasePagination from '@/components/atoms/navigation/BasePagination.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { useResponsive } from '@/core/composables/useResponsive'
import { formatDate } from '@/core/utils/date'
import type { SelectOption } from '@/core/types/ui.types'
import ProgramAlertCard from './ProgramAlertCard.vue'
import ProgramAlertsFilters from './ProgramAlertsFilters.vue'
import ProgramAlertsSummaryBar from './ProgramAlertsSummaryBar.vue'
import ProgramAlertsTable from './ProgramAlertsTable.vue'
import { ALERTS_COPY, ALERT_TYPE_LABEL } from '../../../constants/alert-copy'
import { PROGRAM_ALERTS_PER_PAGE, useProgramAlerts } from '../../../composables/useProgramAlerts'
import { useProgramAlertsUiStore } from '../../../stores/program-alerts-ui.store'
import type { ProgramManagerRef, ProgramTarget } from '../../../types/program.types'
import type { AlertType, ProgramAlertsFilters as Filters } from '../../../types/program-alerts.types'

const props = defineProps<{
  vetGuid: string
  guid: string
  targets: ProgramTarget[]
  managers: ProgramManagerRef[]
  editable: boolean
}>()

const emit = defineEmits<{ edit: [] }>()

const { isMobile } = useResponsive()
const ui = useProgramAlertsUiStore()
ui.bindProgram(props.guid)
watch(() => props.guid, (guid) => ui.bindProgram(guid))

const { status, profile, target, type, page, expandedKeys } = storeToRefs(ui)

const filters = computed<Filters>(() => ({
  status: status.value,
  profile: profile.value,
  target: target.value,
  type: type.value,
}))

const { data, isError, isLoading, isFetching, refetch } = useProgramAlerts(
  () => props.vetGuid,
  () => props.guid,
  filters,
  page,
)

const lines = computed(() => data.value?.data ?? [])
const counters = computed(() => data.value?.meta.counters ?? null)

const hasFilters = computed(
  () => status.value !== 'all' || profile.value !== null || target.value !== null || type.value !== null,
)

const profileOptions = computed<SelectOption[]>(() =>
  props.managers.map((m) => ({ label: m.name, value: m.guid })),
)

const targetOptions = computed<SelectOption[]>(() =>
  props.targets
    .filter((t): t is ProgramTarget & { guid: string } => Boolean(t.guid))
    .map((t) => ({ label: `Grupo del ${formatDate(t.target_date)}`, value: t.guid })),
)

const typeOptions: SelectOption[] = (Object.keys(ALERT_TYPE_LABEL) as AlertType[]).map((value) => ({
  label: ALERT_TYPE_LABEL[value],
  value,
}))

const isProgramEmpty = computed(() => !hasFilters.value && (counters.value?.total ?? 0) === 0)

const emptyFilterMessage = computed(() =>
  status.value === 'problem' ? ALERTS_COPY.emptyProblem : ALERTS_COPY.emptyFilter,
)

function onPageChange({ page: next }: { page: number; perPage: number }) {
  ui.setPage(next)
}
</script>

<template>
  <div class="pat-root" data-testid="program-alerts-tab">
    <BaseErrorState
      v-if="isError && !data"
      :title="ALERTS_COPY.errorTitle"
      message=""
      @retry="refetch()"
    />

    <a-spin v-else-if="isLoading" :spinning="true" class="pat-spin" />

    <template v-else-if="data && counters">
      <BaseEmptyState v-if="isProgramEmpty" :description="ALERTS_COPY.emptyTitle" data-testid="alerts-empty">
        <p class="pat-empty-detail">{{ ALERTS_COPY.emptyDetail }}</p>
        <PermissionGuard v-if="editable" permission="programs.update">
          <BaseButton variant="secondary" @click="emit('edit')">{{ ALERTS_COPY.editProgram }}</BaseButton>
        </PermissionGuard>
      </BaseEmptyState>

      <template v-else>
        <div class="pat-toolbar">
          <ProgramAlertsSummaryBar
            :counters="counters"
            :status="status"
            @update:status="ui.setStatus"
          />
          <ProgramAlertsFilters
            :profile="profile"
            :target="target"
            :type="type"
            :profile-options="profileOptions"
            :target-options="targetOptions"
            :type-options="typeOptions"
            @update:profile="ui.setProfile"
            @update:target="ui.setTarget"
            @update:type="ui.setType"
          />
        </div>

        <BaseEmptyState v-if="!lines.length" :description="emptyFilterMessage" data-testid="alerts-filter-empty" />

        <template v-else>
          <div v-if="isMobile" class="pat-cards" data-testid="alerts-cards">
            <ProgramAlertCard
              v-for="line in lines"
              :key="line.key"
              :line="line"
              :expanded="expandedKeys.includes(line.key)"
              @toggle="ui.toggleExpanded"
            />
          </div>
          <ProgramAlertsTable
            v-else
            :lines="lines"
            :loading="isFetching"
            :expanded-keys="expandedKeys"
            @toggle="ui.toggleExpanded"
          />

          <BasePagination
            :page="data.current_page"
            :total="data.total"
            :per-page="PROGRAM_ALERTS_PER_PAGE"
            :show-size-changer="false"
            @change="onPageChange"
          />
        </template>
      </template>
    </template>
  </div>
</template>

<style scoped>
.pat-toolbar {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
}

.pat-cards {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pat-spin {
  display: block;
  padding: 32px 0;
  text-align: center;
}

.pat-empty-detail {
  margin: 0 0 12px;
}
</style>
