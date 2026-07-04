<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { PlusOutlined, DisconnectOutlined, EyeOutlined } from '@ant-design/icons-vue'
import LinkVetModal from './LinkVetModal.vue'
import { useAdminUnlinkVet } from '../../composables/admin/useAdminUnlinkVet'
import type { VetItem } from '@/modules/vets/types/vet.types'
import { formatDate } from '@/core/utils/date'

const props = defineProps<{
  clientGuid: string
  vets: VetItem[]
}>()

const router = useRouter()
const { unlinkVet, isPending: isUnlinking } = useAdminUnlinkVet(props.clientGuid)

const isModalOpen = ref(false)

const columns = [
  { title: 'Nombre', key: 'name' },
  { title: 'Estado', key: 'status' },
  { title: 'Alta',   key: 'created_at' },
  { title: 'Acciones', key: 'actions', width: 110 },
]
</script>

<template>
  <div class="cvs-root">
    <div class="cvs-toolbar">
      <PermissionGuard permission="clients.create">
        <BaseButton @click="isModalOpen = true">
          <template #icon><PlusOutlined /></template>
          Vincular veterinaria
        </BaseButton>
      </PermissionGuard>
    </div>

    <EmptyState
      v-if="!vets.length"
      message="Este cliente no tiene veterinarias vinculadas."
      icon="🏥"
    />

    <BaseDataTable
      v-else
      :columns="columns"
      :data-source="vets"
      :loading="isUnlinking"
      row-key="guid"
      :scroll="{ x: 600 }"
      :pagination="false"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span class="cvs-name">{{ record.name }}</span>
          <span class="cvs-slug">{{ record.slug }}</span>
        </template>

        <template v-else-if="column.key === 'status'">
          <a-tag v-if="record.is_active" color="success">Activa</a-tag>
          <a-tag v-else color="default">Inactiva</a-tag>
        </template>

        <template v-else-if="column.key === 'created_at'">
          {{ formatDate(record.created_at) }}
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <BaseButton
              variant="row-action"
              size="small"
              tooltip="Ver veterinaria"
              @click="router.push(`/admin/vets/${record.guid}`)"
            >
              <template #icon><EyeOutlined /></template>
            </BaseButton>

            <PermissionGuard permission="clients.delete">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Desvincular"
                danger
                @click="unlinkVet(record)"
              >
                <template #icon><DisconnectOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <LinkVetModal
      v-if="isModalOpen"
      :client-guid="clientGuid"
      @linked="isModalOpen = false"
      @close="isModalOpen = false"
    />
  </div>
</template>

<style scoped>
.cvs-root { padding-top: 4px; }

.cvs-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}

.cvs-name  { font-weight: 600; color: var(--dt-title, #fff); display: block; }
.cvs-slug  { font-size: 11px; color: var(--dt-muted, #6B8CAE); }
</style>
