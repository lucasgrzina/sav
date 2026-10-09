<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftOutlined, StopOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import EstablishmentHealthPlanCalendar from '../../components/tenant/EstablishmentHealthPlanCalendar.vue'
import EstablishmentHealthPlanCancelModal from '../../components/tenant/EstablishmentHealthPlanCancelModal.vue'
import { useEstablishmentHealthPlanDetail } from '../../composables/useEstablishmentHealthPlanDetail'
import {
  useCancelEstablishmentHealthPlanWithModal,
  useConfirmEstablishmentHealthPlanActivity,
} from '../../composables/useEstablishmentHealthPlanMutations'
import type { EstablishmentHealthPlanActivity } from '../../types/establishment-health-plan.types'

const router = useRouter()
const route = useRoute()
const guid = computed(() => route.params.guid as string)

function backToList() {
  router.push({ name: 'vet-health-plans-list' })
}

const { data: plan, isLoading } = useEstablishmentHealthPlanDetail(guid)

const {
  selectedPlan,
  showCancelModal,
  isPending: isCancelling,
  openCancelModal,
  closeCancelModal,
  confirmCancel,
} = useCancelEstablishmentHealthPlanWithModal()

const { mutate: confirmActivity, isPending: isConfirming } = useConfirmEstablishmentHealthPlanActivity()
const confirmingGuid = ref<string | null>(null)

function onConfirmActivity(activity: EstablishmentHealthPlanActivity) {
  if (!plan.value) return
  confirmingGuid.value = activity.guid
  confirmActivity(
    { planGuid: plan.value.guid, activityGuid: activity.guid },
    { onSettled: () => { confirmingGuid.value = null } },
  )
}
</script>

<template>
  <div class="vehpdp-root">
    <div class="vehpdp-header">
      <BaseButton variant="tertiary" @click="backToList">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a planes sanitarios
      </BaseButton>
    </div>

    <a-spin :spinning="isLoading">
      <template v-if="plan">
        <AppHeader
          :title="`Plan sanitario — ${plan.establishment.name}`"
          :subtitle="`${plan.template.name} · Cliente ${plan.client.name} · Año ${plan.year}`"
        >
          <template #actions>
            <PermissionGuard v-if="plan.editable" permission="establishment-health-plans.update">
              <BaseButton danger @click="openCancelModal(plan)">
                <template #icon><StopOutlined /></template>
                Cancelar plan
              </BaseButton>
            </PermissionGuard>
          </template>
        </AppHeader>

        <EstablishmentHealthPlanCalendar
          :activities="plan.activities"
          :confirming-guid="isConfirming ? confirmingGuid : null"
          @confirm="onConfirmActivity"
        />
      </template>
    </a-spin>

    <EstablishmentHealthPlanCancelModal
      v-model="showCancelModal"
      :plan="selectedPlan"
      :is-pending="isCancelling"
      @confirm="confirmCancel"
      @cancel="closeCancelModal"
    />
  </div>
</template>

<style scoped>
.vehpdp-header {
  margin-bottom: 16px;
}
</style>
