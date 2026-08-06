<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { EditOutlined, ArrowLeftOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import TechniqueTypeBadge from '../components/TechniqueTypeBadge.vue'
import TechniqueProtocolsTab from '../components/TechniqueProtocolsTab.vue'
import TechniqueDeleteModal from '../components/TechniqueDeleteModal.vue'
import { useTechniqueDetail } from '../composables/useTechniqueDetail'
import { useDeleteTechnique } from '../composables/useTechniqueMutations'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { data, isLoading } = useTechniqueDetail(computed(() => props.guid))

const {
  mutate: mutateDelete,
  isPending: isDeleting,
  deleteBlockedError,
  clearDeleteError,
} = useDeleteTechnique()

const showDeleteModal = ref(false)

const technique = computed(() => data.value?.technique)

function openDeleteModal() {
  showDeleteModal.value = true
  clearDeleteError()
}

function onDeleteCancel() {
  showDeleteModal.value = false
  clearDeleteError()
}

function onDeleteConfirm() {
  mutateDelete(props.guid, {
    onSuccess: () => {
      router.push('/techniques')
    },
  })
}

const childColumns = [
  { title: 'Nombre', key: 'name', dataIndex: 'name' },
  { title: 'Label Protocolos', key: 'protocols_name', dataIndex: 'protocols_name' },
]
</script>

<template>
  <div>
    <div class="tdp-back">
      <BaseButton variant="tertiary" @click="router.push('/techniques')">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a técnicas
      </BaseButton>
    </div>

    <div v-if="isLoading" class="tdp-loading">
      Cargando técnica...
    </div>

    <template v-else-if="technique">
      <AppHeader :title="technique.name">
        <template #subtitle>
          <TechniqueTypeBadge :type="technique.type" />
        </template>
        <template #actions="{ buttonSize }">
          <PermissionGuard permission="techniques.update">
            <BaseButton
              :size="buttonSize"
              @click="router.push(`/techniques/${technique.guid}/edit`)"
            >
              <template #icon><EditOutlined /></template>
              Editar
            </BaseButton>
          </PermissionGuard>
          <PermissionGuard permission="techniques.delete">
            <BaseButton :size="buttonSize" danger @click="openDeleteModal">
              <template #icon><DeleteOutlined /></template>
              Eliminar
            </BaseButton>
          </PermissionGuard>
        </template>
      </AppHeader>

      <a-tabs class="tdp-tabs">
        <a-tab-pane key="children" tab="Sub-técnicas">
          <a-empty
            v-if="technique.children.length === 0"
            description="Esta técnica no tiene sub-técnicas."
          />
          <BaseDataTable
            v-else
            :columns="childColumns"
            :data-source="technique.children"
            row-key="guid"
            :pagination="false"
            :scroll="{ x: 500 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'protocols_name'">
                <span v-if="record.protocols_name">{{ record.protocols_name }}</span>
                <span v-else class="tdp-muted">—</span>
              </template>
            </template>
          </BaseDataTable>
        </a-tab-pane>

        <a-tab-pane key="protocols" tab="Protocolos">
          <TechniqueProtocolsTab :technique="technique" />
        </a-tab-pane>
      </a-tabs>

      <TechniqueDeleteModal
        v-model="showDeleteModal"
        :technique="technique"
        :is-pending="isDeleting"
        :blocked-error="deleteBlockedError"
        @confirm="onDeleteConfirm"
        @cancel="onDeleteCancel"
      />
    </template>

    <div v-else class="tdp-loading">
      Técnica no encontrada.
    </div>
  </div>
</template>

<style scoped>
.tdp-back {
  margin-bottom: 16px;
}

.tdp-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}

.tdp-tabs {
  margin-top: 8px;
}

.tdp-muted {
  color: var(--dt-muted, #6B8CAE);
}
</style>
