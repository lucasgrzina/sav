<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined, DisconnectOutlined, EyeOutlined, UserAddOutlined } from '@ant-design/icons-vue'
import LinkClientModal from './LinkClientModal.vue'
import { useAdminClientsByVet } from '@/modules/clients/composables/admin/useAdminClientsByVet'
import { useAdminUnlinkClientFromVet } from '@/modules/clients/composables/admin/useAdminUnlinkClientFromVet'
import { formatDate } from '@/core/utils/date'
import type { ClientFilters } from '@/modules/clients/types/client.types'

const props = defineProps<{ vetGuid: string }>()

const router = useRouter()

const filters = reactive<ClientFilters>({ search: '', page: 1, per_page: 15 })
const { data, isLoading } = useAdminClientsByVet(
  computed(() => props.vetGuid),
  computed(() => filters),
)

const { unlinkClient, isPending: isUnlinking } = useAdminUnlinkClientFromVet(props.vetGuid)

const isModalOpen = ref(false)

const columns = [
  { title: 'Nombre',    key: 'name' },
  { title: 'CUIT/Doc.', key: 'tax_id' },
  { title: 'Alta',      key: 'created_at' },
  { title: 'Acciones',  key: 'actions', width: 110 },
]
</script>

<template>
  <div class="vcs-root">
    <div class="vcs-toolbar">
      <a-input-search
        v-model:value="filters.search"
        placeholder="Buscar cliente..."
        allow-clear
        style="max-width: 280px"
        @change="filters.page = 1"
      />
      <div class="vcs-actions">
        <PermissionGuard permission="clients.create">
          <BaseButton
            variant="secondary"
            @click="router.push(`/admin/vets/${props.vetGuid}/clients/new`)"
          >
            <template #icon><UserAddOutlined /></template>
            Agregar cliente
          </BaseButton>
        </PermissionGuard>
        <PermissionGuard permission="clients.create">
          <BaseButton @click="isModalOpen = true">
            <template #icon><PlusOutlined /></template>
            Vincular cliente
          </BaseButton>
        </PermissionGuard>
      </div>
    </div>

    <EmptyState
      v-if="!isLoading && !data?.data.length"
      message="Esta veterinaria no tiene clientes vinculados."
      icon="🐾"
    />

    <BaseDataTable
      v-else
      :columns="columns"
      :data-source="data?.data ?? []"
      :loading="isLoading || isUnlinking"
      row-key="guid"
      :scroll="{ x: 600 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span class="vcs-name">{{ record.name }}</span>
        </template>

        <template v-else-if="column.key === 'tax_id'">
          <span class="vcs-taxid">{{ record.tax_id }}</span>
        </template>

        <template v-else-if="column.key === 'created_at'">
          {{ formatDate(record.created_at) }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Ver cliente"
              @click="router.push(`/admin/clients/${record.guid}`)"
            >
              <template #icon><EyeOutlined /></template>
            </BaseButton>

            <PermissionGuard permission="clients.delete">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Desvincular"
                danger
                @click="unlinkClient(record)"
              >
                <template #icon><DisconnectOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <BasePagination
      :page="filters.page"
      :total="data?.total ?? 0"
      :per-page="filters.per_page"
      @change="({ page, perPage }: { page: number; perPage: number }) => { filters.page = page; filters.per_page = perPage }"
    />

    <LinkClientModal
      v-if="isModalOpen"
      :vet-guid="vetGuid"
      @linked="isModalOpen = false"
      @close="isModalOpen = false"
    />
  </div>
</template>

<style scoped>
.vcs-root { padding-top: 4px; }

.vcs-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.vcs-name  { font-weight: 600; color: var(--dt-title, #fff); }
.vcs-taxid { font-family: monospace; font-size: 12px; color: var(--dt-text, #C8E2EF); }

.vcs-actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>
