<script setup lang="ts">
import { ref, computed } from 'vue'
import { DeleteOutlined } from '@ant-design/icons-vue'
import { useQuery } from '@tanstack/vue-query'
import { listVetsApi } from '@/modules/vets/api/vets.api'
import { adminListClientsApi } from '@/modules/clients/api/admin-clients.api'

const props = defineProps<{
  index: number
  roles: Array<{ value: string; label: string; name: string }>
  loadingRoles: boolean
  fieldErrors: Record<string, string> | null
}>()

const emit = defineEmits<{
  remove: []
  'update:role': [guid: string]
  'update:vet': [guid: string]
  'update:client': [guid: string]
}>()

const selectedRoleGuid = ref<string>('')
const selectedTenantGuid = ref<string>('')

const selectedRoleName = computed(
  () => props.roles.find((r) => r.value === selectedRoleGuid.value)?.name ?? '',
)

const tenantType = computed<'vet' | 'client' | null>(() => {
  if (selectedRoleName.value.startsWith('vet')) return 'vet'
  if (selectedRoleName.value.startsWith('client')) return 'client'
  return null
})

const vetSearch = ref('')
const clientSearch = ref('')

const { data: vetsData, isLoading: isLoadingVets } = useQuery({
  queryKey: computed(() => ['vets-search', props.index, vetSearch.value]),
  queryFn: ({ signal }) => listVetsApi({ search: vetSearch.value, per_page: 20 }, signal),
  enabled: computed(() => tenantType.value === 'vet'),
  staleTime: 1000 * 30,
})

const { data: clientsData, isLoading: isLoadingClients } = useQuery({
  queryKey: computed(() => ['clients-search', props.index, clientSearch.value]),
  queryFn: ({ signal }) => adminListClientsApi({ search: clientSearch.value, per_page: 20 }, signal),
  enabled: computed(() => tenantType.value === 'client'),
  staleTime: 1000 * 30,
})

const vetOptions = computed(
  () => (vetsData.value?.data ?? []).map((v) => ({ value: v.guid, label: v.name })),
)

const clientOptions = computed(
  () => (clientsData.value?.data ?? []).map((c) => ({ value: c.guid, label: c.name })),
)

function onRoleChange(guid: string) {
  selectedRoleGuid.value = guid
  selectedTenantGuid.value = ''
  vetSearch.value = ''
  clientSearch.value = ''
  emit('update:role', guid)
  emit('update:vet', '')
  emit('update:client', '')
}

function onVetChange(guid: string) {
  selectedTenantGuid.value = guid
  emit('update:vet', guid)
}

function onClientChange(guid: string) {
  selectedTenantGuid.value = guid
  emit('update:client', guid)
}
</script>

<template>
  <a-card size="small" style="margin-bottom: 8px">
    <template #extra>
      <a-button
        type="text"
        danger
        size="small"
        @click="emit('remove')"
      >
        <template #icon><DeleteOutlined /></template>
      </a-button>
    </template>

    <a-row :gutter="12">
      <a-col :xs="24" :sm="8">
        <a-form-item
          label="Rol"
          :validate-status="fieldErrors?.[`profiles.${index}.role_guid`] ? 'error' : ''"
          :help="fieldErrors?.[`profiles.${index}.role_guid`] ?? ''"
          style="margin-bottom: 0"
        >
          <a-select
            v-model:value="selectedRoleGuid"
            :loading="loadingRoles"
            :options="roles"
            placeholder="Seleccioná un rol"
            style="width: 100%"
            @change="onRoleChange"
          />
        </a-form-item>
      </a-col>

      <a-col v-if="tenantType === 'vet'" :xs="24" :sm="16">
        <a-form-item
          label="Veterinaria"
          :validate-status="fieldErrors?.[`profiles.${index}.vet_guid`] ? 'error' : ''"
          :help="fieldErrors?.[`profiles.${index}.vet_guid`] ?? ''"
          style="margin-bottom: 0"
        >
          <a-select
            v-model:value="selectedTenantGuid"
            show-search
            :options="vetOptions"
            :loading="isLoadingVets"
            :filter-option="false"
            placeholder="Buscá una veterinaria..."
            style="width: 100%"
            @search="(val: string) => { vetSearch = val }"
            @change="onVetChange"
          />
        </a-form-item>
      </a-col>

      <a-col v-if="tenantType === 'client'" :xs="24" :sm="16">
        <a-form-item
          label="Cliente"
          :validate-status="fieldErrors?.[`profiles.${index}.client_guid`] ? 'error' : ''"
          :help="fieldErrors?.[`profiles.${index}.client_guid`] ?? ''"
          style="margin-bottom: 0"
        >
          <a-select
            v-model:value="selectedTenantGuid"
            show-search
            :options="clientOptions"
            :loading="isLoadingClients"
            :filter-option="false"
            placeholder="Buscá un cliente..."
            style="width: 100%"
            @search="(val: string) => { clientSearch = val }"
            @change="onClientChange"
          />
        </a-form-item>
      </a-col>

      <a-col v-if="!tenantType && selectedRoleGuid" :xs="24" :sm="16">
        <a-form-item label=" " style="margin-bottom: 0">
          <a-typography-text type="warning">
            No se puede determinar el tipo de tenant para este rol.
          </a-typography-text>
        </a-form-item>
      </a-col>
    </a-row>
  </a-card>
</template>
