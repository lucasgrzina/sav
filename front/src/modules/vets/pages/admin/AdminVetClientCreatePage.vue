<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftOutlined, SearchOutlined } from '@ant-design/icons-vue'
import { useMutation } from '@tanstack/vue-query'
import ClientForm from '@/modules/clients/components/forms/ClientForm.vue'
import { useAdminLookupClient } from '@/modules/clients/composables/admin/useAdminLookupClient'
import { useAdminCreateAndLinkClient } from '@/modules/clients/composables/admin/useAdminCreateAndLinkClient'
import { adminLinkVetToClientApi } from '@/modules/clients/api/admin-clients.api'
import { useNotification } from '@/core/composables/useNotification'
import { parseApiError } from '@/core/composables/parseApiError'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { ClientItem } from '@/modules/clients/types/client.types'
import type { ClientCreateForm } from '@/modules/clients/validators/client.validator'

const props = defineProps<{ guid: string }>()

const router = useRouter()
const { success, error: notifyError } = useNotification()

type LookupState =
  | { status: 'idle' }
  | { status: 'searching' }
  | { status: 'found-linkable';  client: ClientItem }
  | { status: 'found-linked';    client: ClientItem }
  | { status: 'not-found';       taxId: string }
  | { status: 'creating' }
  | { status: 'done' }

const taxIdInput = ref('')
const state      = ref<LookupState>({ status: 'idle' })

const {
  data: lookupData,
  isLoading: isSearching,
  isError: isSearchError,
  search,
  reset: resetLookup,
} = useAdminLookupClient(props.guid)

// Mutación inline para vincular cliente existente a la vet (caso found-linkable)
const linkError = ref<string | null>(null)
const { mutateAsync: linkAsync, isPending: isLinking } = useMutation({
  mutationFn: (clientGuid: string) => adminLinkVetToClientApi(clientGuid, props.guid),
  onMutate: () => { linkError.value = null },
  onSuccess: () => { success('Cliente vinculado correctamente') },
  onError: (err: unknown) => {
    const apiError = parseApiError(err)
    linkError.value = apiError.message ?? 'Error al vincular el cliente.'
    notifyError(linkError.value)
  },
})

const {
  mutateAsync: createAsync,
  isPending: isCreating,
  fieldErrors,
  generalError: createError,
} = useAdminCreateAndLinkClient(props.guid)

// Reaccionar al resultado del lookup
watch([lookupData, isSearching, isSearchError], ([data, loading, hasError]) => {
  if (loading) {
    state.value = { status: 'searching' }
    return
  }

  if (hasError) {
    state.value = { status: 'idle' }
    return
  }

  if (data === undefined) return

  if (!data.found) {
    state.value = { status: 'not-found', taxId: taxIdInput.value }
  } else if (data.already_linked) {
    state.value = { status: 'found-linked', client: data.client as ClientItem }
  } else {
    state.value = { status: 'found-linkable', client: data.client as ClientItem }
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
  linkError.value = null
  resetLookup()
}

async function handleLink(clientGuid: string): Promise<void> {
  await linkAsync(clientGuid, {
    onSuccess: () => {
      router.push(`/admin/vets/${props.guid}`)
    },
  })
}

async function handleCreate(values: ClientCreateForm): Promise<void> {
  state.value = { status: 'creating' }
  await createAsync(values, {
    onSuccess: () => router.push(`/admin/vets/${props.guid}`),
    onError:   () => { state.value = { status: 'not-found', taxId: taxIdInput.value } },
  })
}
</script>

<template>
  <div class="avcc-root">
    <BaseButton variant="tertiary" class="avcc-back" @click="router.push(`/admin/vets/${props.guid}`)">
      <template #icon><ArrowLeftOutlined /></template>
      Volver a la veterinaria
    </BaseButton>
    <AppHeader title="Agregar cliente" size="default" />

    <!-- Sección de búsqueda — siempre visible -->
    <div class="avcc-search">
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
    <div v-if="state.status === 'searching'" class="avcc-spinner">
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

      <div class="avcc-card">
        <dl class="avcc-dl">
          <dt>Nombre</dt>
          <dd>{{ state.client.name }}</dd>
          <dt>Identificador fiscal</dt>
          <dd>{{ state.client.tax_id }}</dd>
          <template v-if="state.client.country">
            <dt>País</dt>
            <dd>{{ state.client.country.name }}</dd>
          </template>
          <template v-if="state.client.document_type">
            <dt>Tipo de documento</dt>
            <dd>{{ state.client.document_type.name }}</dd>
          </template>
        </dl>
      </div>

      <a-alert
        v-if="linkError"
        type="error"
        :message="linkError"
        show-icon
      />

      <div class="avcc-actions">
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

      <div class="avcc-card">
        <dl class="avcc-dl">
          <dt>Nombre</dt>
          <dd>{{ state.client.name }}</dd>
          <dt>Identificador fiscal</dt>
          <dd>{{ state.client.tax_id }}</dd>
        </dl>
      </div>

      <div class="avcc-actions">
        <BaseButton
          variant="primary"
          @click="router.push(`/admin/clients/${state.client.guid}`)"
        >
          Ver cliente
        </BaseButton>
        <BaseButton variant="secondary" @click="resetSearch">Buscar otro</BaseButton>
      </div>
    </template>

    <!-- NOT-FOUND / CREATING -->
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

      <div class="avcc-cancel">
        <BaseButton variant="secondary" @click="resetSearch">Volver al paso 1</BaseButton>
      </div>
    </template>
  </div>
</template>

<style scoped>
.avcc-root {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.avcc-back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 0;
  margin-bottom: 16px;
  transition: color 0.15s;
}

.avcc-back:hover {
  color: var(--dt-text, #C8E2EF);
}

.avcc-search {
  display: flex;
  gap: 12px;
  align-items: center;
}

.avcc-spinner {
  display: flex;
  justify-content: center;
  padding: 40px 0;
}

.avcc-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 12px;
  padding: 20px 24px;
}

.avcc-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 20px;
  margin: 0;
}

.avcc-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.avcc-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
}

.avcc-actions {
  display: flex;
  gap: 10px;
}

.avcc-cancel {
  display: flex;
  justify-content: flex-start;
}
</style>
