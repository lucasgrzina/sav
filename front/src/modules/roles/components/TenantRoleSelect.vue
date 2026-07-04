<script setup lang="ts">
import { computed } from 'vue'
import { useTenantRoles } from '../composables/useTenantRoles'
import type { TenantRoleOption } from '../stores/roles-cache.store'

const props = withDefaults(
  defineProps<{
    modelValue: string | string[] | null
    tenantType?: 'vet' | 'client' | 'all'
    multiple?: boolean
    disabled?: boolean
    placeholder?: string
  }>(),
  {
    tenantType: 'all',
    multiple: false,
    disabled: false,
    placeholder: 'Seleccioná un rol',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string | string[] | null]
}>()

const { vetRoles, clientRoles, loading } = useTenantRoles()

const showVets = computed(() => props.tenantType === 'vet' || props.tenantType === 'all')
const showClients = computed(() => props.tenantType === 'client' || props.tenantType === 'all')

// Para modo tenantType específico sin grupos, exponemos las opciones planas
const flatOptions = computed<TenantRoleOption[]>(() => {
  if (props.tenantType === 'vet') return vetRoles.value
  if (props.tenantType === 'client') return clientRoles.value
  return []
})

const useGrouped = computed(() => props.tenantType === 'all')

function onchange(value: string | string[] | undefined) {
  if (value === undefined) {
    emit('update:modelValue', props.multiple ? [] : null)
  } else {
    emit('update:modelValue', value)
  }
}
</script>

<template>
  <a-select
    :value="modelValue ?? undefined"
    :mode="multiple ? 'multiple' : undefined"
    :loading="loading"
    :disabled="disabled"
    :placeholder="placeholder"
    allow-clear
    style="width: 100%"
    @change="onchange"
  >
    <template v-if="useGrouped">
      <a-select-opt-group v-if="showVets" label="Veterinarios">
        <a-select-option
          v-for="role in vetRoles"
          :key="role.value"
          :value="role.value"
        >
          {{ role.label }}
        </a-select-option>
      </a-select-opt-group>

      <a-select-opt-group v-if="showClients" label="Clientes">
        <a-select-option
          v-for="role in clientRoles"
          :key="role.value"
          :value="role.value"
        >
          {{ role.label }}
        </a-select-option>
      </a-select-opt-group>
    </template>

    <template v-else>
      <a-select-option
        v-for="role in flatOptions"
        :key="role.value"
        :value="role.value"
      >
        {{ role.label }}
      </a-select-option>
    </template>
  </a-select>
</template>
