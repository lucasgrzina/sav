<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import RoleForm from './forms/RoleForm.vue'
import { useRole } from '../composables/useRole'
import { useCreateRole } from '../composables/useCreateRole'
import { useUpdateRole } from '../composables/useUpdateRole'
import { usePermissions } from '@/modules/permissions/composables/usePermissions'
import type { RoleFormValues, RoleUpdateFormValues } from '../validators/role.validator'
import type { PermissionGroup } from '../types/role.types'

const props = defineProps<{ guid?: string }>()

const isOpen = defineModel<boolean>({ required: true })
const emit = defineEmits<{ success: [] }>()

const isEditing = computed(() => !!props.guid)
const drawerTitle = computed(() => (isEditing.value ? 'Editar rol' : 'Nuevo rol'))

const { data: role, isLoading: loadingRole } = useRole(computed(() => props.guid ?? ''))
const { data: permissionsData, isLoading: loadingPerms } = usePermissions()

const createRole = useCreateRole()
const updateRole = useUpdateRole()

const permissionGroups = computed<PermissionGroup[]>(() => permissionsData.value ?? [])

const initialValues = computed(() => {
  if (!role.value) return undefined
  return {
    name: role.value.name,
    permissions: role.value.permissions.map((p) => p.guid),
  }
})

const isProtected = computed(() =>
  isEditing.value && ['super-admin', 'admin'].includes(role.value?.name ?? ''),
)

const isMutating = computed(
  () => createRole.isPending.value || updateRole.isPending.value,
)

const activeFieldErrors = computed(() =>
  isEditing.value ? updateRole.fieldErrors.value : createRole.fieldErrors.value,
)

const activeGeneralError = computed(() =>
  isEditing.value ? updateRole.generalError.value : createRole.generalError.value,
)

const formRef = ref<InstanceType<typeof RoleForm> | null>(null)

watch(isOpen, (open) => {
  if (open) {
    if (isEditing.value) {
      updateRole.resetErrors()
    } else {
      createRole.resetErrors()
    }
  }
})

function handleSubmit(values: RoleFormValues | RoleUpdateFormValues) {
  if (isEditing.value) {
    const payload =
      role.value?.type === 'tenant'
        ? { permissions: values.permissions }
        : { name: (values as RoleFormValues).name, permissions: values.permissions }

    updateRole.mutate(
      { guid: props.guid!, payload },
      {
        onSuccess: () => {
          isOpen.value = false
          emit('success')
        },
      },
    )
  } else {
    createRole.mutate(values as RoleFormValues, {
      onSuccess: () => {
        isOpen.value = false
        emit('success')
      },
    })
  }
}
</script>

<template>
  <BaseDrawer v-model="isOpen" :title="drawerTitle">
    <div v-if="isEditing && loadingRole" class="rfd-loading">
      Cargando rol...
    </div>

    <template v-else>
      <a-alert
        v-if="activeGeneralError && !activeFieldErrors"
        :message="activeGeneralError"
        type="error"
        show-icon
        style="margin-bottom: 16px"
      />

      <RoleForm
        ref="formRef"
        :initial-values="initialValues"
        :permission-groups="permissionGroups"
        :loading-permissions="loadingPerms"
        :loading="isMutating"
        :is-protected="isProtected"
        :field-errors="activeFieldErrors"
        :role-type="isEditing ? role?.type : undefined"
        hide-footer
        @submit="handleSubmit"
      />
    </template>

    <template #footer>
      <a-space style="justify-content: flex-end; width: 100%">
        <BaseButton variant="secondary" @click="isOpen = false">Cancelar</BaseButton>
        <BaseButton variant="primary" :loading="isMutating" @click="formRef?.submit()">
          {{ isEditing ? 'Actualizar' : 'Crear rol' }}
        </BaseButton>
      </a-space>
    </template>
  </BaseDrawer>
</template>

<style scoped>
.rfd-loading {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
