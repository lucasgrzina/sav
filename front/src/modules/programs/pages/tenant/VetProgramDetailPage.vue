<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftOutlined, EditOutlined, StopOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { usePermission } from '@/core/composables/usePermissions'
import { formatDate } from '@/core/utils/date'
import ProgramAlertsTab from '../../components/tenant/alerts/ProgramAlertsTab.vue'
import ProgramCancelModal from '../../components/tenant/ProgramCancelModal.vue'
import ProgramInfoCards from '../../components/tenant/ProgramInfoCards.vue'
import ProgramTargetsTimeline from '../../components/tenant/ProgramTargetsTimeline.vue'
import { ALERTS_COPY } from '../../constants/alert-copy'
import { useProgramAlerts } from '../../composables/useProgramAlerts'
import { useProgramDetail } from '../../composables/useProgramDetail'
import { useCancelProgramWithModal } from '../../composables/useProgramMutations'

// DEC-11/DEC-15/DEC-16: vista de detalle nueva — muestra tareas/alertas proyectadas del
// protocolo (simulación de solo lectura, no dispara ni persiste nada real) mapeadas a los
// responsables seleccionados según el rol asignado a cada alerta.
const route = useRoute()
const router = useRouter()

const vetGuid = computed(() => route.params.vetGuid as string)
const guid = computed(() => route.params.guid as string)

const { data: program, isLoading } = useProgramDetail(guid)

const { can } = usePermission()
const canReadAlerts = computed(() => can('programs.alerts.read'))

// The active tab is mirrored in ?tab=schedule|alerts. "alerts" needs programs.alerts.read.
const activeTab = computed<'schedule' | 'alerts'>({
  get: () => (route.query.tab === 'alerts' && canReadAlerts.value ? 'alerts' : 'schedule'),
  set: (tab) => {
    router.replace({ query: { ...route.query, tab } })
  },
})

// Same unfiltered query the tab starts with (shared cache entry): feeds the red dot
// without waiting for the tab to be opened.
const defaultFilters = ref({ status: 'all' as const, profile: null, target: null, type: null })
const defaultPage = ref(1)
const { data: alertsSummary } = useProgramAlerts(vetGuid, guid, defaultFilters, defaultPage)
const hasProblems = computed(() => (alertsSummary.value?.meta.counters.problem ?? 0) > 0)

function backToList() {
  router.push({ name: 'vet-programs-list' })
}

function goToEdit() {
  router.push({ name: 'vet-programs-edit', params: { vetGuid: vetGuid.value, guid: guid.value } })
}

const {
  selectedProgram,
  showCancelModal,
  isPending: isCancelling,
  openCancelModal,
  closeCancelModal,
  confirmCancel,
} = useCancelProgramWithModal()
</script>

<template>
  <div class="vpdp-root">
    <div class="vpdp-header">
      <BaseButton variant="tertiary" @click="backToList">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a programas
      </BaseButton>
    </div>

    <a-spin :spinning="isLoading">
      <template v-if="program">
        <AppHeader :title="`Programa de ${program.client.name}`" :subtitle="program.establishment.name">
          <template #actions="{ buttonSize }">
            <PermissionGuard v-if="program.editable" permission="programs.update">
              <BaseButton :size="buttonSize" variant="secondary" @click="goToEdit">
                <template #icon><EditOutlined /></template>
                Editar
              </BaseButton>
            </PermissionGuard>
            <PermissionGuard v-if="program.editable" permission="programs.update">
              <BaseButton :size="buttonSize" variant="secondary" danger @click="openCancelModal(program)">
                <template #icon><StopOutlined /></template>
                Cancelar programa
              </BaseButton>
            </PermissionGuard>
          </template>
        </AppHeader>

        <ProgramInfoCards :program="program" />

        <a-alert
          v-if="program.cancelled_at"
          type="warning"
          show-icon
          class="vpdp-cancelled"
          :message="ALERTS_COPY.cancelledBanner(formatDate(program.cancelled_at))"
          data-testid="program-cancelled-banner"
        />

        <a-tabs v-model:active-key="activeTab" class="vpdp-tabs">
          <a-tab-pane key="schedule" :tab="ALERTS_COPY.scheduleTab">
            <BaseCard title="Grupos">
              <ProgramTargetsTimeline :targets="program.targets" />
            </BaseCard>
          </a-tab-pane>
          <a-tab-pane v-if="canReadAlerts" key="alerts">
            <template #tab>
              <a-badge :dot="hasProblems" color="red" data-testid="alerts-tab-badge">
                <span>{{ ALERTS_COPY.tab }}</span>
              </a-badge>
            </template>
            <ProgramAlertsTab
              :vet-guid="vetGuid"
              :guid="guid"
              :targets="program.targets"
              :managers="program.managers"
              :editable="program.editable"
              @edit="goToEdit"
            />
          </a-tab-pane>
        </a-tabs>
      </template>
    </a-spin>

    <ProgramCancelModal
      v-model="showCancelModal"
      :program="selectedProgram"
      :is-pending="isCancelling"
      @confirm="confirmCancel"
      @cancel="closeCancelModal"
    />
  </div>
</template>

<style scoped>
.vpdp-header {
  margin-bottom: 16px;
}

.vpdp-cancelled {
  margin-top: 16px;
}

.vpdp-tabs {
  margin-top: 16px;
}
</style>
