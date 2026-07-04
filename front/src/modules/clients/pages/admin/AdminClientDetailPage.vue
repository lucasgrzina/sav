<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientVetsSection from '../../components/admin/ClientVetsSection.vue'
import AdminClientStaffSection from '../../components/admin/AdminClientStaffSection.vue'
import EstablishmentsSection from '../../components/EstablishmentsSection.vue'
import ClientInfoCards from '../../components/ClientInfoCards.vue'
import { useAdminClient } from '../../composables/admin/useAdminClient'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { data: client, isLoading } = useAdminClient(computed(() => props.guid))
</script>

<template>
  <div>
    <BaseButton variant="tertiary" class="acdp-back" @click="router.push('/admin/clients')">
      <template #icon><ArrowLeftOutlined /></template>
      Volver a clientes
    </BaseButton>

    <div v-if="isLoading" class="acdp-loading">
      Cargando cliente...
    </div>

    <template v-else-if="client">
      <AppHeader :title="client.name" :subtitle="client.tax_id">
        <template #actions="{ buttonSize }">
          <PermissionGuard permission="clients.update">
            <BaseButton :size="buttonSize" @click="router.push(`/admin/clients/${client.guid}/edit`)">
              <template #icon><EditOutlined /></template>
              Editar
            </BaseButton>
          </PermissionGuard>
        </template>
      </AppHeader>

      <ClientInfoCards :client="client" />

      <!-- Tabs con secciones -->
      <a-tabs class="acdp-tabs">
        <a-tab-pane key="establishments" tab="Establecimientos">
          <EstablishmentsSection :client-guid="props.guid" mode="admin" />
        </a-tab-pane>

        <a-tab-pane key="vets" tab="Veterinarias vinculadas">
          <ClientVetsSection
            :client-guid="props.guid"
            :vets="client.vets ?? []"
          />
        </a-tab-pane>

        <a-tab-pane key="staff" tab="Staff">
          <AdminClientStaffSection :client-guid="props.guid" />
        </a-tab-pane>
      </a-tabs>
    </template>

    <div v-else class="acdp-loading">
      No se encontró el cliente.
    </div>
  </div>
</template>

<style scoped>
.acdp-back {
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
.acdp-back:hover { color: var(--dt-accent, #1AE5A0); }

.acdp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.acdp-tabs { margin-top: 8px; }
</style>
