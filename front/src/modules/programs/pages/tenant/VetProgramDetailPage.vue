<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftOutlined, EditOutlined, StopOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import ProgramCancelModal from '../../components/tenant/ProgramCancelModal.vue'
import ProgramInfoCards from '../../components/tenant/ProgramInfoCards.vue'
import ProgramTargetsTimeline from '../../components/tenant/ProgramTargetsTimeline.vue'
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

        <BaseCard title="Grupos" class="vpdp-groups-card">
          <ProgramTargetsTimeline :targets="program.targets" />
        </BaseCard>
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

.vpdp-groups-card {
  margin-top: 16px;
}
</style>
