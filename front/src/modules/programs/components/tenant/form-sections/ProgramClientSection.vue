<script setup lang="ts">
import { computed } from 'vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import type { SelectOption } from '@/core/types/ui.types'

const props = withDefaults(
  defineProps<{
    clientOptions: SelectOption[]
    establishmentOptions: SelectOption[]
    loadingClients?: boolean
    loadingEstablishments?: boolean
    errors: {
      client_id?: string
      establishment_id?: string
    }
  }>(),
  { loadingClients: false, loadingEstablishments: false },
)

const clientId = defineModel<string>('clientId', { required: true })
const establishmentId = defineModel<string>('establishmentId', { required: true })

// Mientras la query de opciones (cliente/establecimiento) sigue en curso, el select puede tener
// ya un guid seteado (form hidratado en modo edición) sin la opción correspondiente todavía
// cargada. En ese caso a-select cae a mostrar el guid crudo como texto. Enmascaramos el valor
// visible con '' durante la carga — el spinner de :loading cubre el estado — y lo
// restauramos apenas la query resuelve.
const clientSelectValue = computed<string>({
  get: () => (props.loadingClients ? '' : clientId.value),
  set: (value) => { clientId.value = value },
})
const establishmentSelectValue = computed<string>({
  get: () => (props.loadingEstablishments ? '' : establishmentId.value),
  set: (value) => { establishmentId.value = value },
})
</script>

<template>
  <BaseCard title="Datos del cliente">
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item
          label="Cliente"
          :validate-status="errors.client_id ? 'error' : ''"
          :help="errors.client_id ?? ''"
          required
        >
          <BaseSelect
            v-model="clientSelectValue"
            :options="clientOptions"
            :loading="loadingClients"
            placeholder="Seleccioná un cliente"
          />
        </a-form-item>
      </a-col>

      <a-col :xs="24" :md="12">
        <a-form-item
          label="Establecimiento"
          :validate-status="errors.establishment_id ? 'error' : ''"
          :help="errors.establishment_id ?? ''"
          required
        >
          <BaseSelect
            v-model="establishmentSelectValue"
            :options="establishmentOptions"
            :disabled="!clientId"
            :loading="loadingEstablishments"
            placeholder="Seleccioná un establecimiento"
          />
        </a-form-item>
      </a-col>
    </a-row>
  </BaseCard>
</template>
