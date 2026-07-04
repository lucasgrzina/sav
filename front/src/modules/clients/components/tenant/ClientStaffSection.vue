<script setup lang="ts">
import { useRouter } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import ClientStaffTable from './ClientStaffTable.vue'
import { useClientStaff } from '../../composables/useClientStaff'
import { useRemoveClientStaff } from '../../composables/useRemoveClientStaff'
import { useToggleClientStaffBlock } from '../../composables/useToggleClientStaffBlock'
import type { ClientStaffItem } from '../../types/client.types'

const props = defineProps<{
  vetGuid: string
  clientGuid: string
}>()

const router = useRouter()

const { data: staff, isLoading }                     = useClientStaff(props.vetGuid, props.clientGuid)
const { removeStaff, isPending: isRemoving }         = useRemoveClientStaff(props.vetGuid, props.clientGuid)
const { toggleBlock, isPending: isTogglingBlock }    = useToggleClientStaffBlock(props.vetGuid, props.clientGuid)

function goToCreate(): void {
  router.push(`/vets/${props.vetGuid}/clients/${props.clientGuid}/staff/new`)
}

function goToEdit(member: ClientStaffItem): void {
  router.push(`/vets/${props.vetGuid}/clients/${props.clientGuid}/staff/${member.guid}/edit`)
}
</script>

<template>
  <div class="css-root">
    <div class="css-toolbar">
      <PermissionGuard permission="clients.staff.create">
        <BaseButton @click="goToCreate">
          <template #icon><PlusOutlined /></template>
          Agregar miembro
        </BaseButton>
      </PermissionGuard>
    </div>

    <EmptyState
      v-if="!isLoading && !staff?.length"
      message="Este cliente no tiene miembros de staff."
    />

    <ClientStaffTable
      v-else
      :staff="staff ?? []"
      :loading="isLoading || isRemoving || isTogglingBlock"
      @edit="goToEdit"
      @toggle-block="toggleBlock"
      @unlink="removeStaff"
    />
  </div>
</template>

<style scoped>
.css-root { padding-top: 4px; }

.css-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}
</style>
