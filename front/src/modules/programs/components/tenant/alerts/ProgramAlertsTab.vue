<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseEmptyState from '@/components/atoms/feedback/BaseEmptyState.vue'
import BaseErrorState from '@/components/atoms/feedback/BaseErrorState.vue'
import BaseConfirmDialog from '@/components/atoms/overlays/BaseConfirmDialog.vue'
import BasePagination from '@/components/atoms/navigation/BasePagination.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { useResponsive } from '@/core/composables/useResponsive'
import { formatDate } from '@/core/utils/date'
import type { SelectOption } from '@/core/types/ui.types'
import ProgramAlertCard from './ProgramAlertCard.vue'
import ProgramAlertsFilters from './ProgramAlertsFilters.vue'
import ProgramAlertsSummaryBar from './ProgramAlertsSummaryBar.vue'
import ProgramAlertsOutOfSyncBanner from './ProgramAlertsOutOfSyncBanner.vue'
import ProgramAlertsTable from './ProgramAlertsTable.vue'
import ProgramWarningsBanner from './ProgramWarningsBanner.vue'
import { ACTIONS_COPY, ALERTS_COPY, ALERT_TYPE_LABEL } from '../../../constants/alert-copy'
import { PROGRAM_ALERTS_PER_PAGE, useProgramAlerts } from '../../../composables/useProgramAlerts'
import { useRebuildProgramAlerts } from '../../../composables/useRebuildProgramAlerts'
import { useRetryProgramAlert } from '../../../composables/useRetryProgramAlert'
import { useProgramAlertsUiStore } from '../../../stores/program-alerts-ui.store'
import type { ProgramManagerRef, ProgramTarget } from '../../../types/program.types'
import type { AlertType, ProgramAlertLine, ProgramAlertsFilters as Filters } from '../../../types/program-alerts.types'

const props = defineProps<{
  vetGuid: string
  guid: string
  clientGuid: string
  targets: ProgramTarget[]
  managers: ProgramManagerRef[]
  editable: boolean
}>()

const emit = defineEmits<{ edit: [] }>()

const router = useRouter()
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

const meta = computed(() => data.value?.meta ?? null)

const { mutate: retry, isPending: isRetrying } = useRetryProgramAlert(() => props.vetGuid, () => props.guid)
const { mutate: rebuild, isPending: isRebuilding } = useRebuildProgramAlerts(() => props.vetGuid, () => props.guid)

const pendingRetry = ref<ProgramAlertLine | null>(null)
const showRetryConfirm = computed({
  get: () => pendingRetry.value !== null,
  set: (open) => {
    if (!open) pendingRetry.value = null
  },
})
const retryingGuid = computed(() => (isRetrying.value ? pendingRetry.value?.retry?.recipient_guid ?? null : null))

function askRetry(line: ProgramAlertLine) {
  pendingRetry.value = line
}

function confirmRetry() {
  const recipientGuid = pendingRetry.value?.retry?.recipient_guid
  pendingRetry.value = null
  if (recipientGuid) retry(recipientGuid)
}

// "Ir a contactos" only makes sense for client staff: vet staff contacts are not edited here.
const clientProfileGuids = computed(
  () => new Set(props.managers.filter((m) => m.origin === 'client').map((m) => m.guid)),
)

function canGoToContacts(line: ProgramAlertLine): boolean {
  return clientProfileGuids.value.has(line.recipient.profile_guid)
}

function goToContacts(line: ProgramAlertLine) {
  router.push({
    name: 'clients-staff-edit',
    params: { vetGuid: props.vetGuid, clientGuid: props.clientGuid, profileGuid: line.recipient.profile_guid },
  })
}

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
      <div v-if="meta && (meta.out_of_sync || meta.warnings.length)" class="pat-banners">
        <ProgramAlertsOutOfSyncBanner
          v-if="meta.out_of_sync && !meta.program_cancelled_at"
          :loading="isRebuilding"
          @rebuild="rebuild()"
        />
        <ProgramWarningsBanner v-if="!meta.program_cancelled_at" :warnings="meta.warnings" />
      </div>

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
              :can-go-to-contacts="canGoToContacts(line)"
              :retrying="retryingGuid !== null && retryingGuid === line.retry?.recipient_guid"
              @toggle="ui.toggleExpanded"
              @retry="askRetry"
              @contacts="goToContacts"
            />
          </div>
          <ProgramAlertsTable
            v-else
            :lines="lines"
            :loading="isFetching"
            :expanded-keys="expandedKeys"
            :can-go-to-contacts="canGoToContacts"
            :retrying-guid="retryingGuid"
            @toggle="ui.toggleExpanded"
            @retry="askRetry"
            @contacts="goToContacts"
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

    <BaseConfirmDialog
      v-model="showRetryConfirm"
      :title="ACTIONS_COPY.retryConfirmTitle"
      :message="ACTIONS_COPY.retryConfirmMessage(pendingRetry?.recipient.name ?? '')"
      :ok-text="ACTIONS_COPY.retry"
      @confirm="confirmRetry"
    />
  </div>
</template>

<style scoped>
.pat-banners {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 16px;
}

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
