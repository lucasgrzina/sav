<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientsTable from '../../components/tenant/ClientsTable.vue'
import ClientFilters from '../../components/ClientFilters.vue'
import { useClients } from '../../composables/useClients'
import { useUnlinkClient } from '../../composables/useUnlinkClient'
import { useClientsUiStore } from '../../stores/clients-ui.store'
import type { ClientItem, ClientFilters as ClientFiltersType } from '../../types/client.types'

const router   = useRouter()
const route    = useRoute()
const vetGuid  = computed(() => route.params.vetGuid as string)
const uiStore  = useClientsUiStore()

const filtersModel = computed<ClientFiltersType>({
  get: () => uiStore.filters,
  set: (val) => Object.assign(uiStore.filters, val),
})

const { data, isLoading } = useClients(
  computed(() => ({ ...uiStore.filters, search: uiStore.debouncedSearch })),
)

const { unlinkClient } = useUnlinkClient()

async function handleUnlink(client: ClientItem): Promise<void> {
  await unlinkClient(client)
}
</script>

<template>
  <div>
    <AppHeader
      title="Clientes"
      subtitle="Clientes vinculados a esta veterinaria."
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="clients.create">
          <BaseButton :size="buttonSize" @click="router.push(`/vets/${vetGuid}/clients/new`)">
            <template #icon><PlusOutlined /></template>
            Agregar cliente
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <ClientFilters v-model:filters="filtersModel" />

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="No se encontraron clientes vinculados a esta veterinaria."
      icon="🐾"
    >
      <PermissionGuard permission="clients.create">
        <BaseButton variant="primary" class="mt-3" @click="router.push(`/vets/${vetGuid}/clients/new`)">
          <template #icon><PlusOutlined /></template>
          Agregar primer cliente
        </BaseButton>
      </PermissionGuard>
    </EmptyState>

    <ClientsTable
      v-else
      :clients="data?.data ?? []"
      :loading="isLoading"
      @unlink="handleUnlink"
    />

    <BasePagination
      :page="uiStore.filters.page"
      :total="data?.total ?? 0"
      :per-page="uiStore.filters.per_page"
      @change="({ page, perPage }: { page: number; perPage: number }) => { uiStore.filters.page = page; uiStore.filters.per_page = perPage }"
    />
  </div>
</template>

<style scoped>
.mt-3 { margin-top: 12px; }

.clp-btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 18px;
  border-radius: 10px;
  border: none;
  background: var(--dt-accent, #1AE5A0);
  color: #060F1C;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s;
}
.clp-btn-primary:hover { opacity: 0.85; }
</style>
