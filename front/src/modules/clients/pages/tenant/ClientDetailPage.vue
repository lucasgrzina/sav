<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import EstablishmentsSection from '../../components/EstablishmentsSection.vue'
import ContactsSection from '../../components/tenant/ContactsSection.vue'
import ClientStaffSection from '../../components/tenant/ClientStaffSection.vue'
import ClientInfoCards from '../../components/ClientInfoCards.vue'
import { useClient } from '../../composables/useClient'
import { useUnlinkClient } from '../../composables/useUnlinkClient'

const props = defineProps<{ guid: string }>()

const router  = useRouter()
const route   = useRoute()
const vetGuid = computed(() => route.params.vetGuid as string)

const { data: client, isLoading } = useClient(computed(() => props.guid))
const { unlinkClient, isPending: isUnlinking } = useUnlinkClient()

async function handleUnlink(): Promise<void> {
  if (!client.value) return
  await unlinkClient(client.value)
  router.push(`/vets/${vetGuid.value}/clients`)
}
</script>

<template>
  <div>
    <BaseButton variant="tertiary" class="cdp-back" @click="router.push(`/vets/${vetGuid}/clients`)">
      <template #icon><ArrowLeftOutlined /></template>
      Volver a clientes
    </BaseButton>

    <div v-if="isLoading" class="cdp-loading">
      Cargando cliente...
    </div>

    <template v-else-if="client">
      <AppHeader :title="client.name" :subtitle="client.tax_id">
        <template #actions="{ buttonSize }">
          <PermissionGuard permission="clients.update">
            <BaseButton :size="buttonSize" @click="router.push(`/vets/${vetGuid}/clients/${client.guid}/edit`)">
              <template #icon><EditOutlined /></template>
              Editar
            </BaseButton>
          </PermissionGuard>

          <PermissionGuard permission="clients.delete">
            <BaseButton
              :size="buttonSize"
              danger
              :loading="isUnlinking"
              @click="handleUnlink"
            >
              Desvincular
            </BaseButton>
          </PermissionGuard>
        </template>
      </AppHeader>

      <ClientInfoCards :client="client" />

      <!-- Tabs con secciones -->
      <a-tabs class="cdp-tabs">
        <a-tab-pane key="establishments" tab="Establecimientos">
          <EstablishmentsSection :client-guid="guid" mode="tenant" />
        </a-tab-pane>

        <a-tab-pane key="contacts" tab="Contactos">
          <ContactsSection :client-guid="guid" />
        </a-tab-pane>

        <a-tab-pane key="staff" tab="Staff">
          <ClientStaffSection :vet-guid="vetGuid" :client-guid="guid" />
        </a-tab-pane>
      </a-tabs>
    </template>

    <div v-else class="cdp-loading">
      No se encontró el cliente.
    </div>
  </div>
</template>

<style scoped>
.cdp-back {
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
.cdp-back:hover { color: var(--dt-accent, #1AE5A0); }

.cdp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.cdp-tabs { margin-top: 8px; }
</style>
