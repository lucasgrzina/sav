<script setup lang="ts">
import { ref, computed } from 'vue'
import { SearchOutlined } from '@ant-design/icons-vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { useDebounce } from '@/core/composables/useDebounce'
import { adminListClientsApi, adminLinkVetToClientApi } from '@/modules/clients/api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { ClientItem } from '@/modules/clients/types/client.types'

const props = defineProps<{ vetGuid: string }>()

const emit = defineEmits<{
  linked: []
  close: []
}>()

const searchInput = ref('')
const debouncedSearch = useDebounce(searchInput, 400)

const queryClient = useQueryClient()
const { success, error } = useNotification()
const generalError = ref<string | null>(null)
const isPending    = ref(false)

const { data, isFetching } = useQuery({
  queryKey: ['clients-search', debouncedSearch],
  queryFn: ({ signal }) => adminListClientsApi({ search: debouncedSearch.value, per_page: 10 }, signal),
  enabled: computed(() => debouncedSearch.value.length >= 2),
  staleTime: 1000 * 30,
})

const clients = computed(() => data.value?.data ?? [])

async function handleSelect(client: ClientItem): Promise<void> {
  isPending.value    = true
  generalError.value = null
  try {
    await adminLinkVetToClientApi(client.guid, props.vetGuid)
    queryClient.invalidateQueries({ queryKey: ['admin-vet-clients', props.vetGuid] })
    success('Cliente vinculado correctamente')
    emit('linked')
  } catch (err) {
    const apiError = parseApiError(err)
    generalError.value = apiError.message ?? 'Error al vincular el cliente.'
    error(generalError.value)
  } finally {
    isPending.value = false
  }
}
</script>

<template>
  <a-modal
    open
    title="Vincular cliente existente"
    :footer="null"
    @cancel="emit('close')"
  >
    <div class="lcm-search">
      <a-input
        v-model:value="searchInput"
        placeholder="Buscar por nombre o CUIT..."
        allow-clear
      >
        <template #prefix><SearchOutlined /></template>
      </a-input>
      <p class="lcm-hint">Escribí al menos 2 caracteres para buscar.</p>
    </div>

    <div v-if="generalError" class="lcm-error">{{ generalError }}</div>

    <div v-if="isFetching" class="lcm-loading">Buscando...</div>

    <div v-else-if="clients.length === 0 && debouncedSearch.length >= 2" class="lcm-empty">
      No se encontraron clientes.
    </div>

    <ul v-else class="lcm-list">
      <li
        v-for="client in clients"
        :key="client.guid"
        class="lcm-item"
      >
        <div class="lcm-item-info">
          <span class="lcm-item-name">{{ client.name }}</span>
          <span class="lcm-item-taxid">{{ client.tax_id }}</span>
        </div>
        <BaseButton
          variant="primary"
          size="small"
          :loading="isPending"
          @click="handleSelect(client)"
        >
          Vincular
        </BaseButton>
      </li>
    </ul>
  </a-modal>
</template>

<style scoped>
.lcm-search { margin-bottom: 16px; }

.lcm-hint {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
  margin: 6px 0 0;
}

.lcm-error {
  padding: 10px 14px;
  border-radius: 8px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 12px;
}

.lcm-loading,
.lcm-empty {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 12px 0;
  text-align: center;
}

.lcm-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lcm-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border-radius: 10px;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  background: var(--dt-card, #0E2038);
}

.lcm-item-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.lcm-item-name  { font-weight: 600; font-size: 13px; color: var(--dt-title, #fff); }
.lcm-item-taxid { font-size: 11px; font-family: monospace; color: var(--dt-muted, #6B8CAE); }
</style>
