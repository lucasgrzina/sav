<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import ProtocolInfoCard from '../../components/tenant/ProtocolInfoCard.vue'
import ProtocolTaskTimeline from '../../components/tenant/ProtocolTaskTimeline.vue'
import { useVetProtocolDetail } from '../../composables/useVetProtocolDetail'

const route = useRoute()
const router = useRouter()

const guid = computed(() => route.params.guid as string)

const { data: protocol, isLoading } = useVetProtocolDetail(guid)

function backToList() {
  router.push({ name: 'vet-protocols-list' })
}
</script>

<template>
  <div class="vptp-root">
    <div class="vptp-header">
      <BaseButton variant="tertiary" @click="backToList">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a protocolos
      </BaseButton>
    </div>

    <a-spin :spinning="isLoading">
      <template v-if="protocol">
        <AppHeader :title="protocol.name" :subtitle="protocol.technique.name" />

        <ProtocolInfoCard :protocol="protocol" class="vptp-info-card" />

        <BaseCard title="Tareas y alertas">
          <ProtocolTaskTimeline :tasks="protocol.tasks" />
        </BaseCard>
      </template>
    </a-spin>
  </div>
</template>

<style scoped>
.vptp-header {
  margin-bottom: 16px;
}

.vptp-info-card {
  margin-bottom: 16px;
}
</style>
