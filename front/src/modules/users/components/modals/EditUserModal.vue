<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import UserForm from '../forms/UserForm.vue'
import TenantProfileEditRow from '../forms/TenantProfileEditRow.vue'
import TenantProfileRow from '../forms/TenantProfileRow.vue'
import { useUpdateUser } from '../../composables/useUpdateUser'
import { useUser } from '../../composables/useUser'
import { useRolesCacheStore } from '@/modules/roles/stores/roles-cache.store'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import { addUserProfileApi } from '../../api/users.api'
import type { UserItem, UserUpdatePayload } from '../../types/user.types'

const props = defineProps<{ user: UserItem | null }>()
const isOpen = defineModel<boolean>({ default: false })

const { mutate, isPending, fieldErrors, generalError, resetErrors } = useUpdateUser()

watch(isOpen, (open) => {
  if (open) {
    resetErrors()
    resetAddProfile()
  }
})

const userGuid = computed(() => props.user?.guid ?? '')
const { data: userDetail, isFetching: isLoadingUser } = useUser(userGuid)

const showForm = computed(() => Boolean(props.user && userDetail.value))
const showLoading = computed(() => Boolean(props.user && isLoadingUser.value && !userDetail.value))

const initialValues = computed(() => ({
  first_name: userDetail.value?.first_name ?? props.user?.first_name ?? '',
  last_name: userDetail.value?.last_name ?? props.user?.last_name ?? '',
  email: userDetail.value?.email ?? props.user?.email ?? '',
  role_guids: userDetail.value?.roles?.map((r) => r.guid) ?? [],
}))

// Roles de tenant para los selectores de perfiles
const rolesStore = useRolesCacheStore()
rolesStore.fetchTenantRoles()

const tenantRoles = computed(() => rolesStore.tenantRoles)
const isLoadingTenantRoles = computed(() => rolesStore.loading)

const hasTenantProfiles = computed(() => (userDetail.value?.profiles?.length ?? 0) > 0)
const isTenantUser = computed(() => (userDetail.value?.roles?.length ?? 0) === 0)

function rolesForTenantType(tenantType: 'vet' | 'client') {
  const source = tenantType === 'vet' ? rolesStore.vetRoles : rolesStore.clientRoles
  return source.map((r) => ({ value: r.value, label: r.label }))
}

// --- Agregar perfil inline ---
const showAddProfile = ref(false)
const newRoleGuid = ref('')
const newVetGuid = ref('')
const newClientGuid = ref('')
const addProfileError = ref<string | null>(null)

function resetAddProfile() {
  showAddProfile.value = false
  newRoleGuid.value = ''
  newVetGuid.value = ''
  newClientGuid.value = ''
  addProfileError.value = null
}

const queryClient = useQueryClient()
const { success, error: notifyError } = useNotification()

const { mutate: addProfile, isPending: isAddingProfile } = useMutation({
  mutationFn: () =>
    addUserProfileApi(userGuid.value, {
      role_guid: newRoleGuid.value,
      vet_guid: newVetGuid.value || undefined,
      client_guid: newClientGuid.value || undefined,
    }),
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['user', userGuid.value] })
    queryClient.invalidateQueries({ queryKey: ['users'] })
    success('Perfil agregado correctamente')
    resetAddProfile()
  },
  onError: (err: unknown) => {
    const apiError = parseApiError(err)
    addProfileError.value = apiError.message ?? 'Error al agregar el perfil.'
    notifyError('Error al agregar el perfil')
  },
})

const canAddProfile = computed(() => {
  if (!newRoleGuid.value) return false
  const role = tenantRoles.value.find((r) => r.value === newRoleGuid.value)
  if (!role) return false
  if (role.name.startsWith('vet')) return Boolean(newVetGuid.value)
  if (role.name.startsWith('client')) return Boolean(newClientGuid.value)
  return false
})

function handleSubmit(values: UserUpdatePayload) {
  if (!props.user) return
  mutate(
    { guid: props.user.guid, payload: values },
    {
      onSuccess: () => {
        isOpen.value = false
      },
    },
  )
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Editar usuario" :width="640">
    <a-alert
      v-if="generalError && !fieldErrors"
      :message="generalError"
      type="error"
      show-icon
      style="margin-bottom: 16px"
    />
    <div v-if="showLoading" style="text-align: center; padding: 24px 0">
      <a-spin size="default" />
    </div>
    <UserForm
      v-if="showForm"
      mode="edit"
      :initial-values="initialValues"
      :loading="isPending"
      :field-errors="fieldErrors"
      :role-type="isTenantUser ? 'tenant' : 'platform'"
      @submit="handleSubmit"
    >
      <template #footer>
        <!-- Perfiles de tenant existentes -->
        <template v-if="hasTenantProfiles || showAddProfile">
          <a-divider style="margin: 12px 0 8px">Perfiles de tenant</a-divider>

          <TenantProfileEditRow
            v-for="profile in userDetail?.profiles"
            :key="profile.guid"
            :profile="profile"
            :user-guid="userDetail!.guid"
            :role-options="rolesForTenantType(profile.tenant_type)"
          />
        </template>

        <!-- Fila para agregar nuevo perfil -->
        <template v-if="showAddProfile">
          <div style="margin-top: 8px">
            <a-alert
              v-if="addProfileError"
              :message="addProfileError"
              type="error"
              show-icon
              style="margin-bottom: 8px"
            />
            <TenantProfileRow
              :index="0"
              :roles="tenantRoles"
              :loading-roles="isLoadingTenantRoles"
              :field-errors="null"
              @remove="resetAddProfile"
              @update:role="(g) => { newRoleGuid = g; newVetGuid = ''; newClientGuid = '' }"
              @update:vet="(g) => newVetGuid = g"
              @update:client="(g) => newClientGuid = g"
            />
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px">
              <BaseButton variant="secondary" size="small" @click="resetAddProfile">Cancelar</BaseButton>
              <BaseButton
                variant="primary"
                size="small"
                :loading="isAddingProfile"
                :disabled="!canAddProfile"
                @click="addProfile()"
              >
                Confirmar
              </BaseButton>
            </div>
          </div>
        </template>

        <!-- Botón para mostrar el formulario de nuevo perfil -->
        <!-- a-button type="dashed" no mapeado directamente a BaseButton, se conserva para mantener estilo visual dashed -->
        <a-button
          v-if="!showAddProfile && isTenantUser"
          type="dashed"
          block
          style="margin: 8px 0 16px"
          @click="showAddProfile = true"
        >
          <template #icon><PlusOutlined /></template>
          Agregar perfil de tenant
        </a-button>

        <!-- Footer principal -->
        <a-form-item style="margin-bottom: 0; text-align: right">
          <a-space>
            <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
            <BaseButton variant="primary" html-type="submit" :loading="isPending">
              Guardar cambios
            </BaseButton>
          </a-space>
        </a-form-item>
      </template>
    </UserForm>
  </BaseModal>
</template>
