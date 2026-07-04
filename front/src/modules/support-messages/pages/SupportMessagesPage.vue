<script setup lang="ts">
import { computed } from 'vue'
import { PlusOutlined } from '@ant-design/icons-vue'
import SupportMessagesTable from '../components/SupportMessagesTable.vue'
import CreateSupportMessageModal from '../components/modals/CreateSupportMessageModal.vue'
import SupportMessageDetailModal from '../components/modals/SupportMessageDetailModal.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { useSupportMessages } from '../composables/useSupportMessages'
import { useDeleteSupportMessage } from '../composables/useDeleteSupportMessage'
import { useSupportMessagesUiStore } from '../stores/support-messages.store'

const uiStore = useSupportMessagesUiStore()
const { deleteMessage } = useDeleteSupportMessage()

const { data, isLoading } = useSupportMessages(computed(() => uiStore.filters))

const showCreate = computed({
  get: () => uiStore.activeModal === 'create',
  set: (v) => { if (!v) uiStore.closeModal() },
})

const showDetail = computed({
  get: () => uiStore.activeModal === 'detail',
  set: (v) => { if (!v) uiStore.closeModal() },
})
</script>

<template>
  <div>
    <AppHeader
      title="Soporte"
      subtitle="Enviá mensajes al equipo de SAV"
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="support-messages.create">
          <BaseButton :size="buttonSize" @click="uiStore.openModal('create')">
            <template #icon><PlusOutlined /></template>
            Nuevo mensaje
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <SupportMessagesTable
      :messages="data?.data ?? []"
      :loading="isLoading"
      @view="(msg) => uiStore.openModal('detail', msg)"
      @delete="deleteMessage"
    />

    <BasePagination
      :page="uiStore.filters.page"
      :total="data?.total ?? 0"
      :per-page="uiStore.filters.per_page"
      @change="({ page, perPage }) => { uiStore.filters.page = page; uiStore.filters.per_page = perPage }"
    />

    <CreateSupportMessageModal v-model="showCreate" />

    <SupportMessageDetailModal
      v-model="showDetail"
      :message="uiStore.selectedMessage"
    />
  </div>
</template>
