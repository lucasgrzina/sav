<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { SearchOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientForm from './ClientForm.vue'
import { useLookupClient } from '../../composables/useLookupClient'
import { useLinkClient } from '../../composables/useLinkClient'
import { useCreateClient } from '../../composables/useCreateClient'
import type { ClientItem } from '../../types/client.types'
import type { ClientCreateForm } from '../../validators/client.validator'

type LookupState =
  | { status: 'idle' }
  | { status: 'searching' }
  | { status: 'found-linkable';  client: ClientItem }
  | { status: 'found-linked';    client: ClientItem }
  | { status: 'not-found';       taxId: string }
  | { status: 'creating' }
  | { status: 'done' }

const emit = defineEmits<{
  success: [client: ClientItem]
}>()

const router  = useRouter()
const route   = useRoute()
const vetGuid = () => route.params.vetGuid as string

const taxIdInput = ref('')
const state      = ref<LookupState>({ status: 'idle' })

const { data: lookupData, isLoading: isSearching, isError: isSearchError, search, reset: resetLookup } = useLookupClient()
const { mutateAsync: linkAsync, isPending: isLinking, generalError: linkError } = useLinkClient()
const { mutateAsync: createAsync, isPending: isCreating, fieldErrors, generalError: createError } = useCreateClient()

// Reaccionar al resultado del lookup
watch([lookupData, isSearching, isSearchError], ([data, loading, hasError]) => {
  if (loading) {
    state.value = { status: 'searching' }
    return
  }

  if (hasError) {
    // Se queda en idle para que el usuario pueda reintentar
    state.value = { status: 'idle' }
    return
  }

  if (data === undefined) return

  if (!data.found) {
    state.value = { status: 'not-found', taxId: taxIdInput.value }
  } else if (data.already_linked) {
    state.value = { status: 'found-linked', client: data.client }
  } else {
    state.value = { status: 'found-linkable', client: data.client }
  }
})

function handleSearch(): void {
  if (!taxIdInput.value.trim()) return
  state.value = { status: 'searching' }
  search(taxIdInput.value.trim())
}

function resetSearch(): void {
  taxIdInput.value = ''
  state.value = { status: 'idle' }
  resetLookup()
}

async function handleLink(clientGuid: string): Promise<void> {
  await linkAsync(clientGuid, {
    onSuccess: (client) => {
      state.value = { status: 'done' }
      emit('success', client)
    },
  })
}

async function handleCreate(values: ClientCreateForm): Promise<void> {
  state.value = { status: 'creating' }
  await createAsync(values, {
    onSuccess: (client) => {
      state.value = { status: 'done' }
      emit('success', client)
    },
    onError: () => {
      state.value = { status: 'not-found', taxId: taxIdInput.value }
    },
  })
}
</script>

<template>
  <div class="clf-container">
    <!-- Sección de búsqueda — siempre visible -->
    <div class="clf-search">
      <a-input
        v-model:value="taxIdInput"
        placeholder="Ingresá el CUIT o identificador fiscal"
        size="large"
        allow-clear
        @press-enter="handleSearch"
      />
      <BaseButton
        variant="primary"
        size="large"
        :loading="state.status === 'searching'"
        :disabled="!taxIdInput.trim()"
        @click="handleSearch"
      >
        <template #icon><SearchOutlined /></template>
        Buscar
      </BaseButton>
    </div>

    <!-- Error de búsqueda -->
    <a-alert
      v-if="isSearchError"
      type="error"
      message="Error al buscar. Intentá nuevamente."
      show-icon
    />

    <!-- SEARCHING -->
    <div v-if="state.status === 'searching'" class="clf-spinner">
      <a-spin size="large" />
    </div>

    <!-- FOUND-LINKABLE -->
    <template v-else-if="state.status === 'found-linkable'">
      <a-alert
        type="info"
        message="Cliente encontrado en el sistema"
        description="Este cliente existe pero no está vinculado a esta veterinaria."
        show-icon
      />

      <div class="clf-card">
        <dl class="clf-dl">
          <dt>Nombre</dt>
          <dd>{{ state.client.name }}</dd>
          <dt>Identificador fiscal</dt>
          <dd>{{ state.client.tax_id }}</dd>
          <dt v-if="state.client.country">País</dt>
          <dd v-if="state.client.country">{{ state.client.country.name }}</dd>
          <dt v-if="state.client.document_type">Tipo de documento</dt>
          <dd v-if="state.client.document_type">{{ state.client.document_type.name }}</dd>
        </dl>
      </div>

      <a-alert
        v-if="linkError"
        type="error"
        :message="linkError"
        show-icon
      />

      <div class="clf-actions">
        <BaseButton
          variant="primary"
          :loading="isLinking"
          @click="handleLink(state.client.guid)"
        >
          Vincular a esta veterinaria
        </BaseButton>
        <BaseButton variant="secondary" @click="resetSearch">Cancelar</BaseButton>
      </div>
    </template>

    <!-- FOUND-LINKED -->
    <template v-else-if="state.status === 'found-linked'">
      <a-alert
        type="warning"
        message="Este cliente ya está vinculado a esta veterinaria"
        show-icon
      />

      <div class="clf-card">
        <dl class="clf-dl">
          <dt>Nombre</dt>
          <dd>{{ state.client.name }}</dd>
          <dt>Identificador fiscal</dt>
          <dd>{{ state.client.tax_id }}</dd>
        </dl>
      </div>

      <div class="clf-actions">
        <BaseButton
          variant="primary"
          @click="router.push(`/vets/${vetGuid()}/clients/${state.client.guid}`)"
        >
          Ver detalle
        </BaseButton>
        <BaseButton variant="secondary" @click="resetSearch">Buscar otro</BaseButton>
      </div>
    </template>

    <!-- NOT-FOUND -->
    <template v-else-if="state.status === 'not-found' || state.status === 'creating'">
      <a-alert
        type="info"
        message="No se encontró ningún cliente con ese identificador"
        description="Completá los datos para crear el cliente en el sistema y vincularlo a esta veterinaria."
        show-icon
      />

      <a-alert
        v-if="createError"
        type="error"
        :message="createError"
        show-icon
      />

      <ClientForm
        mode="create"
        :initial-values="{ tax_id: state.status === 'not-found' ? state.taxId : taxIdInput }"
        :loading="state.status === 'creating'"
        :field-errors="fieldErrors"
        @submit="handleCreate"
      />

      <div class="clf-cancel">
        <BaseButton variant="secondary" @click="resetSearch">Cancelar búsqueda</BaseButton>
      </div>
    </template>
  </div>
</template>

<style scoped>
.clf-container {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.clf-search {
  display: flex;
  gap: 12px;
  align-items: center;
}

.clf-spinner {
  display: flex;
  justify-content: center;
  padding: 40px 0;
}

.clf-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 12px;
  padding: 20px 24px;
}

.clf-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 20px;
  margin: 0;
}

.clf-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.clf-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
}

.clf-actions {
  display: flex;
  gap: 10px;
}

.clf-cancel {
  display: flex;
  justify-content: flex-start;
}
</style>
