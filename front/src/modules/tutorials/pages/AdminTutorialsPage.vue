<script setup lang="ts">
import { computed } from 'vue'
import { PlusOutlined } from '@ant-design/icons-vue'
import TutorialsTable from '../components/TutorialsTable.vue'
import CreateTutorialModal from '../components/modals/CreateTutorialModal.vue'
import EditTutorialModal from '../components/modals/EditTutorialModal.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { useTutorials } from '../composables/useTutorials'
import { useDeleteTutorial } from '../composables/useDeleteTutorial'
import { useTutorialsUiStore } from '../stores/tutorials-ui.store'

const uiStore = useTutorialsUiStore()
const { deleteTutorial } = useDeleteTutorial()

const { data, isLoading } = useTutorials()

const showCreate = computed({
  get: () => uiStore.activeModal === 'create',
  set: (v) => { if (!v) uiStore.closeModal() },
})

const showEdit = computed({
  get: () => uiStore.activeModal === 'edit',
  set: (v) => { if (!v) uiStore.closeModal() },
})
</script>

<template>
  <div>
    <AppHeader
      title="Tutoriales"
      subtitle="Gestioná los tutoriales de capacitación del sistema"
    >
      <template #actions="{ buttonSize }">
        <PermissionGuard permission="tutorials.create">
          <BaseButton :size="buttonSize" @click="uiStore.openModal('create')">
            <template #icon><PlusOutlined /></template>
            Nuevo tutorial
          </BaseButton>
        </PermissionGuard>
      </template>
    </AppHeader>

    <TutorialsTable
      :tutorials="data ?? []"
      :loading="isLoading"
      @edit="(tutorial) => uiStore.openModal('edit', tutorial)"
      @delete="deleteTutorial"
    />

    <CreateTutorialModal v-model="showCreate" />
    <EditTutorialModal v-model="showEdit" :tutorial="uiStore.selectedTutorial" />
  </div>
</template>
