<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

import VetActionButtons  from '../../components/VetActionButtons.vue'

import VetClientsSection from '../../components/VetClientsSection.vue'
import VetStaffSection   from '../../components/VetStaffSection.vue'
import VetInfoCards      from '../../components/VetInfoCards.vue'
import { useVet }        from '../../composables/useVet'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { data: vet, isLoading } = useVet(computed(() => props.guid))

const initials = computed(() => {
  if (!vet.value?.name) return '??'
  return vet.value.name
    .split(' ')
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('')
})
</script>

<template>
  <div>
    <BaseButton variant="tertiary" class="avd-back" @click="router.push('/admin/vets')">
      <template #icon><ArrowLeftOutlined /></template>
      Volver a veterinarias
    </BaseButton>

    <div v-if="isLoading" class="avd-loading">
      Cargando veterinaria...
    </div>

    <template v-else-if="vet">
      <AppHeader :title="vet.name" :subtitle="vet.slug">
        <template #actions="{ buttonSize }">
          
          <VetActionButtons :vet="vet" :size="buttonSize"/>
          <PermissionGuard permission="vets.update">
            <BaseButton :size="buttonSize" @click="router.push(`/admin/vets/${vet.guid}/edit`)">
              <template #icon><EditOutlined /></template>
              Editar
            </BaseButton>
          </PermissionGuard>
        </template>
      </AppHeader>

      <!--div v-if="vet.logo_path || initials" class="avd-avatar-wrap">
        <img
          v-if="vet.logo_path"
          :src="vet.logo_path"
          :alt="vet.name"
          class="avd-logo"
        />
        <div v-else class="avd-avatar-placeholder">
          {{ initials }}
        </div>
      </div-->

      <VetInfoCards :vet="vet" />

      <a-tabs class="avd-tabs">
        <a-tab-pane key="staff" tab="Staff">
          <PermissionGuard permission="vets.staff.read">
            <VetStaffSection :vet-guid="props.guid" />
          </PermissionGuard>
        </a-tab-pane>

        <a-tab-pane key="clients" tab="Clientes vinculados">
          <VetClientsSection :vet-guid="props.guid" />
        </a-tab-pane>
      </a-tabs>
    </template>

    <div v-else class="avd-loading">
      No se encontró la veterinaria.
    </div>
  </div>
</template>

<style scoped>
.avd-back {
  background: none;
  border: none;
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  margin-bottom: 16px;
  transition: color 0.15s;
}
.avd-back:hover { color: var(--dt-accent, #1AE5A0); }

.avd-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.avd-avatar-wrap {
  margin-bottom: 20px;
}

.avd-logo {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  object-fit: cover;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
}

.avd-avatar-placeholder {
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

.avd-tabs { margin-top: 24px; }
</style>
