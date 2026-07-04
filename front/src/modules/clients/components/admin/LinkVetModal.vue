<script setup lang="ts">
import { ref, computed } from 'vue'
import { SearchOutlined } from '@ant-design/icons-vue'
import { useQuery } from '@tanstack/vue-query'
import { useDebounce } from '@/core/composables/useDebounce'
import { listVetsApi } from '@/modules/vets/api/vets.api'
import { useAdminLinkVet } from '../../composables/admin/useAdminLinkVet'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { VetItem } from '@/modules/vets/types/vet.types'

const props = defineProps<{ clientGuid: string }>()

const emit = defineEmits<{
  linked: []
  close: []
}>()

const searchInput = ref('')
const debouncedSearch = useDebounce(searchInput, 400)

const { data, isFetching } = useQuery({
  queryKey: ['vets-search', debouncedSearch],
  queryFn: ({ signal }) => listVetsApi({ search: debouncedSearch.value, per_page: 10 }, signal),
  enabled: computed(() => debouncedSearch.value.length >= 2),
  staleTime: 1000 * 30,
})

const vets = computed(() => data.value?.data ?? [])

const { mutateAsync: linkVet, isPending, generalError } = useAdminLinkVet(props.clientGuid)

async function handleSelect(vet: VetItem): Promise<void> {
  try {
    await linkVet(vet.guid)
    emit('linked')
  } catch {
    // el error ya es manejado por onError en useAdminLinkVet
  }
}
</script>

<template>
  <a-modal
    open
    title="Vincular veterinaria"
    :footer="null"
    @cancel="emit('close')"
  >
    <div class="lvm-search">
      <a-input
        v-model:value="searchInput"
        placeholder="Buscar por nombre o CUIT..."
        allow-clear
      >
        <template #prefix><SearchOutlined /></template>
      </a-input>
      <p class="lvm-hint">Escribí al menos 2 caracteres para buscar.</p>
    </div>

    <div v-if="generalError" class="lvm-error">{{ generalError }}</div>

    <div v-if="isFetching" class="lvm-loading">Buscando...</div>

    <div v-else-if="vets.length === 0 && debouncedSearch.length >= 2" class="lvm-empty">
      No se encontraron veterinarias.
    </div>

    <ul v-else class="lvm-list">
      <li
        v-for="vet in vets"
        :key="vet.guid"
        class="lvm-item"
      >
        <div class="lvm-item-info">
          <span class="lvm-item-name">{{ vet.name }}</span>
          <span class="lvm-item-slug">{{ vet.slug }}</span>
        </div>
        <a-tag :color="vet.is_active ? 'success' : 'default'" class="lvm-item-status">
          {{ vet.is_active ? 'Activa' : 'Inactiva' }}
        </a-tag>
        <BaseButton
          variant="primary"
          size="small"
          :loading="isPending"
          @click="handleSelect(vet)"
        >
          Vincular
        </BaseButton>
      </li>
    </ul>
  </a-modal>
</template>

<style scoped>
.lvm-search { margin-bottom: 16px; }

.lvm-hint {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
  margin: 6px 0 0;
}

.lvm-error {
  padding: 10px 14px;
  border-radius: 8px;
  border: 1px solid rgba(255, 90, 106, 0.3);
  background: rgba(255, 90, 106, 0.08);
  color: #FF5A6A;
  font-size: 13px;
  margin-bottom: 12px;
}

.lvm-loading,
.lvm-empty {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 12px 0;
  text-align: center;
}

.lvm-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lvm-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  border-radius: 10px;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  background: var(--dt-card, #0E2038);
}

.lvm-item-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.lvm-item-name { font-weight: 600; font-size: 13px; color: var(--dt-title, #fff); }
.lvm-item-slug { font-size: 11px; color: var(--dt-muted, #6B8CAE); }
.lvm-item-status { flex-shrink: 0; }
</style>
