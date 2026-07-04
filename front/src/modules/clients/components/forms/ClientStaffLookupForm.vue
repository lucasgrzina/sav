<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { SearchOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ClientStaffAssignForm from './ClientStaffAssignForm.vue'
import ClientStaffNewForm from './ClientStaffNewForm.vue'
import { useLookupClientStaff } from '../../composables/useLookupClientStaff'
import { useCreateClientStaff } from '../../composables/useCreateClientStaff'
import { useAssignClientStaff } from '../../composables/useAssignClientStaff'
import type { ClientStaffLookupResult, ClientStaffCreatePayload, ClientStaffContactFormItem } from '../../types/client.types'

type LookupUser = NonNullable<ClientStaffLookupResult['user']>

type LookupState =
  | { status: 'idle' }
  | { status: 'searching' }
  | { status: 'found-linkable'; user: LookupUser }
  | { status: 'found-linked';   user: LookupUser }
  | { status: 'not-found';      email: string }
  | { status: 'creating' }
  | { status: 'done' }

const emit = defineEmits<{
  success: []
}>()

const router     = useRouter()
const route      = useRoute()
const vetGuid    = computed(() => route.params.vetGuid as string)
const clientGuid = computed(() => route.params.clientGuid as string)

const emailInput = ref('')
const state      = ref<LookupState>({ status: 'idle' })

const {
  data: lookupData,
  isLoading: isSearching,
  isError: isSearchError,
  search,
  reset: resetLookup,
} = useLookupClientStaff()

const {
  mutateAsync: createAsync,
  isPending: isCreating,
  fieldErrors,
  generalError: createError,
} = useCreateClientStaff()

const {
  mutateAsync: assignAsync,
  isPending: isAssigning,
  generalError: assignError,
} = useAssignClientStaff()

watch([lookupData, isSearching, isSearchError], ([result, loading, hasError]) => {
  if (loading) {
    state.value = { status: 'searching' }
    return
  }

  if (hasError) {
    state.value = { status: 'idle' }
    return
  }

  if (result === undefined) return

  if (!result.found) {
    state.value = { status: 'not-found', email: emailInput.value }
  } else if (result.already_linked) {
    state.value = { status: 'found-linked', user: result.user as LookupUser }
  } else {
    state.value = { status: 'found-linkable', user: result.user as LookupUser }
  }
})

function handleSearch(): void {
  if (!emailInput.value.trim()) return
  state.value = { status: 'searching' }
  search(emailInput.value.trim())
}

function resetSearch(): void {
  emailInput.value = ''
  state.value      = { status: 'idle' }
  resetLookup()
}

async function handleAssign(values: { user_guid: string; role_guid: string; contacts: ClientStaffContactFormItem[] }): Promise<void> {
  await assignAsync(values, {
    onSuccess: () => {
      state.value = { status: 'done' }
      emit('success')
    },
  })
}

async function handleCreate(values: ClientStaffCreatePayload): Promise<void> {
  state.value = { status: 'creating' }
  await createAsync(values, {
    onSuccess: () => {
      state.value = { status: 'done' }
      emit('success')
    },
    onError: () => {
      state.value = { status: 'not-found', email: emailInput.value }
    },
  })
}
</script>

<template>
  <div class="cslf-container">
    <!-- Sección de búsqueda - siempre visible -->
    <div class="cslf-search">
      <a-input
        v-model:value="emailInput"
        placeholder="Ingresá el email del usuario"
        size="large"
        allow-clear
        type="email"
        @press-enter="handleSearch"
      />
      <BaseButton
        variant="primary"
        size="large"
        :loading="state.status === 'searching'"
        :disabled="!emailInput.trim()"
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
    <div v-if="state.status === 'searching'" class="cslf-spinner">
      <a-spin size="large" />
    </div>

    <!-- FOUND-LINKABLE -->
    <template v-else-if="state.status === 'found-linkable'">
      <a-alert
        type="info"
        message="Usuario encontrado en el sistema"
        description="Este usuario existe pero no pertenece a este cliente todavía."
        show-icon
      />

      <a-alert
        v-if="assignError"
        type="error"
        :message="assignError"
        show-icon
      />

      <ClientStaffAssignForm
        :user="state.user"
        :loading="isAssigning"
        :field-errors="null"
        @submit="handleAssign"
        @cancel="resetSearch"
      />
    </template>

    <!-- FOUND-LINKED -->
    <template v-else-if="state.status === 'found-linked'">
      <a-alert
        type="warning"
        message="Este usuario ya forma parte del staff de este cliente"
        show-icon
      />

      <div class="cslf-card">
        <dl class="cslf-dl">
          <dt>Nombre</dt>
          <dd>{{ state.user.first_name }} {{ state.user.last_name }}</dd>
          <dt>Email</dt>
          <dd>{{ state.user.email }}</dd>
        </dl>
      </div>

      <div class="cslf-actions">
        <BaseButton
          variant="primary"
          @click="router.push(`/vets/${vetGuid}/clients/${clientGuid}`)"
        >
          Ver staff
        </BaseButton>
        <BaseButton variant="secondary" @click="resetSearch">Buscar otro</BaseButton>
      </div>
    </template>

    <!-- NOT-FOUND o CREATING -->
    <template v-else-if="state.status === 'not-found' || state.status === 'creating'">
      <a-alert
        type="info"
        message="No se encontró ningún usuario con ese email"
        description="Completá los datos para crear el usuario e incorporarlo al equipo."
        show-icon
      />

      <a-alert
        v-if="createError"
        type="error"
        :message="createError"
        show-icon
      />

      <ClientStaffNewForm
        :initial-email="state.status === 'not-found' ? state.email : emailInput"
        :loading="isCreating || state.status === 'creating'"
        :field-errors="fieldErrors"
        @submit="handleCreate"
        @cancel="resetSearch"
      />
    </template>
  </div>
</template>

<style scoped>
.cslf-container {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.cslf-search {
  display: flex;
  gap: 12px;
  align-items: center;
}

.cslf-spinner {
  display: flex;
  justify-content: center;
  padding: 40px 0;
}

.cslf-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 12px;
  padding: 20px 24px;
}

.cslf-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 20px;
  margin: 0;
}

.cslf-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.cslf-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
}

.cslf-actions {
  display: flex;
  gap: 10px;
}
</style>
