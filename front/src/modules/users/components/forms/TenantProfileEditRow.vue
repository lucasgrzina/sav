<script setup lang="ts">
import { ref } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { MedicineBoxOutlined, TeamOutlined } from '@ant-design/icons-vue'
import { updateUserProfileRoleApi } from '@/modules/users/api/users.api'
import { useNotification } from '@/core/composables/useNotification'
import type { UserProfileItem } from '@/modules/users/types/user.types'

const props = defineProps<{
  profile: UserProfileItem
  userGuid: string
  roleOptions: Array<{ value: string; label: string }>
}>()

const { success, error } = useNotification()
const queryClient = useQueryClient()

const selectedRoleGuid = ref(props.profile.role.guid)

const { mutate, isPending } = useMutation({
  mutationFn: (roleGuid: string) =>
    updateUserProfileRoleApi(props.userGuid, props.profile.guid, roleGuid),
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['user', props.userGuid] })
    queryClient.invalidateQueries({ queryKey: ['users'] })
    success('Rol actualizado')
  },
  onError: () => {
    selectedRoleGuid.value = props.profile.role.guid
    error('Error al actualizar el rol')
  },
})

function onRoleChange(guid: string) {
  mutate(guid)
}
</script>

<template>
  <div style="display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid #f0f0f0">
    <div style="display: flex; align-items: center; gap: 6px; min-width: 160px">
      <MedicineBoxOutlined v-if="profile.tenant_type === 'vet'" style="color: #1677ff" />
      <TeamOutlined v-else style="color: #52c41a" />
      <span style="font-size: 13px; font-weight: 500">{{ profile.tenant_name }}</span>
    </div>
    <div style="flex: 1">
      <a-select
        v-model:value="selectedRoleGuid"
        :options="roleOptions"
        :loading="isPending"
        style="width: 100%"
        size="small"
        @change="onRoleChange"
      />
    </div>
  </div>
</template>
