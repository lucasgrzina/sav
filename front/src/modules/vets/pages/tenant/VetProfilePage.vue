<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { EditOutlined } from '@ant-design/icons-vue'
import { useVetStore } from '@/core/stores/vet.store'
import VetStatusBadge from '@/modules/vets/components/VetStatusBadge.vue'
import VetInfoCards   from '@/modules/vets/components/VetInfoCards.vue'
import { getVetStatus } from '@/modules/vets/api/vets.mapper'

const route    = useRoute()
const router   = useRouter()
const vetStore = useVetStore()
const vet      = computed(() => vetStore.currentVet)

const initials = computed(() => {
  if (!vet.value?.name) return '??'
  return vet.value.name
    .split(' ')
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('')
})

const editPath = computed(() => `/vets/${route.params.vetGuid}/perfil/editar`)
</script>

<template>
  <div>
    <template v-if="vet">
      <AppHeader :title="vet.name" :subtitle="vet.slug">
        <template #actions="{ buttonSize }">
          <VetStatusBadge :status="getVetStatus(vet)" />
          <BaseButton :size="buttonSize" @click="router.push(editPath)">
            <template #icon><EditOutlined /></template>
            Editar perfil
          </BaseButton>
        </template>
      </AppHeader>

      <!--div class="vp-avatar-wrap">
        <img
          v-if="vet.logo_path"
          :src="vet.logo_path"
          :alt="vet.name"
          class="vp-logo"
        />
        <div v-else class="vp-avatar-placeholder">
          {{ initials }}
        </div>
      </div-->

      <VetInfoCards :vet="vet" />
    </template>

    <div v-else class="vp-empty">
      Cargando perfil...
    </div>
  </div>
</template>

<style scoped>
.vp-avatar-wrap {
  margin-bottom: 20px;
}

.vp-logo {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  object-fit: cover;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
}

.vp-avatar-placeholder {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  background: var(--dt-accent, #1AE5A0);
  color: #000;
  font-family: 'Syne', sans-serif;
  font-size: 22px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.vp-empty {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
