<script setup lang="ts">
import { ref } from 'vue'
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import ContactFormModal from '../modals/ContactFormModal.vue'
import { useClientContacts } from '../../composables/useClientContacts'
import { useDeleteContact } from '../../composables/useDeleteContact'
import type { ContactItem } from '../../types/client.types'

const props = defineProps<{
  clientGuid: string
}>()

const { data: contacts, isLoading } = useClientContacts(() => props.clientGuid)
const { deleteContact, isPending: isDeleting } = useDeleteContact(props.clientGuid)

const isModalOpen = ref(false)
const editingContact = ref<Partial<ContactItem> | undefined>()
const editingGuid = ref<string | undefined>()
const modalMode = ref<'create' | 'edit'>('create')

function openCreateModal(): void {
  editingContact.value = undefined
  editingGuid.value = undefined
  modalMode.value = 'create'
  isModalOpen.value = true
}

function openEditModal(contact: ContactItem): void {
  editingContact.value = contact
  editingGuid.value = contact.guid
  modalMode.value = 'edit'
  isModalOpen.value = true
}

const CONTACT_TYPE_LABELS: Record<string, string> = {
  phone:    'Teléfono',
  email:    'Email',
  whatsapp: 'WhatsApp',
}

function getTypeLabel(type: string): string {
  return CONTACT_TYPE_LABELS[type] ?? type
}

const columns = [
  { title: 'Tipo',     key: 'type' },
  { title: 'Valor',    key: 'value' },
  { title: 'Etiqueta', key: 'label' },
  { title: 'Flags',    key: 'flags' },
  { title: 'Acciones', key: 'actions', width: 100 },
]
</script>

<template>
  <div class="cs-section">
    <div class="cs-header">
      <h4 class="cs-title">Contactos</h4>
      <PermissionGuard permission="clients.update">
        <BaseButton @click="openCreateModal">
          <template #icon><PlusOutlined /></template>
          Nuevo contacto
        </BaseButton>
      </PermissionGuard>
    </div>

    <BaseDataTable
      :columns="columns"
      :data-source="contacts ?? []"
      :loading="isLoading"
      row-key="guid"
      :pagination="false"
      :scroll="{ x: 500 }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'type'">
          <a-tag>{{ getTypeLabel(record.type) }}</a-tag>
        </template>

        <template v-else-if="column.key === 'value'">
          <span class="cs-value">{{ record.value }}</span>
        </template>

        <template v-else-if="column.key === 'label'">
          <span v-if="record.label">{{ record.label }}</span>
          <span v-else class="cs-muted">—</span>
        </template>

        <template v-else-if="column.key === 'flags'">
          <div class="cs-flags">
            <a-tag v-if="record.is_primary" color="green">Principal</a-tag>
            <a-tag v-if="record.use_for_alerts" color="blue">Alertas</a-tag>
          </div>
        </template>

        <template v-else-if="column.key === 'actions'">
          <BaseTableActions>
            <PermissionGuard permission="clients.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Editar"
                @click="openEditModal(record)"
              >
                <template #icon><EditOutlined /></template>
              </BaseButton>
            </PermissionGuard>

            <PermissionGuard permission="clients.update">
              <BaseButton
                variant="row-action"
                size="small"
                tooltip="Eliminar"
                danger
                :loading="isDeleting"
                @click="deleteContact(record)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </PermissionGuard>
          </BaseTableActions>
        </template>
      </template>
    </BaseDataTable>

    <ContactFormModal
      v-model="isModalOpen"
      :client-guid="clientGuid"
      :mode="modalMode"
      :initial="editingContact"
      :contact-guid="editingGuid"
    />
  </div>
</template>

<style scoped>
.cs-section { display: flex; flex-direction: column; gap: 16px; }

.cs-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.cs-title {
  font-family: 'Syne', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0;
}

.cs-value  { font-size: 13px; color: var(--dt-text, #C8E2EF); }
.cs-muted  { color: var(--dt-muted, #6B8CAE); font-style: italic; }
.cs-flags  { display: flex; gap: 4px; flex-wrap: wrap; }
</style>
