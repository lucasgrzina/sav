<script setup lang="ts">
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import type { SelectOption } from '@/core/types/ui.types'

// Cascada calcada de VetProtocolFormDrawer.vue (useTechniqueTree): técnica raíz -> sub-técnica.
// El label de "Protocolo" es dinámico (subTechnique.protocols_name, DEC-13) — se resuelve en el
// componente padre (VetProgramFormPage) y viaja como prop porque ahí vive la query de técnicas.
withDefaults(
  defineProps<{
    rootTechniqueOptions: SelectOption[]
    subTechniqueOptions: SelectOption[]
    protocolOptions: SelectOption[]
    protocolLabel: string
    loadingTechniques?: boolean
    loadingProtocols?: boolean
    errors: {
      technique_id?: string
      protocol_id?: string
    }
  }>(),
  { loadingTechniques: false, loadingProtocols: false },
)

const rootTechniqueId = defineModel<string>('rootTechniqueId', { required: true })
const subTechniqueId = defineModel<string>('subTechniqueId', { required: true })
const protocolId = defineModel<string>('protocolId', { required: true })
</script>

<template>
  <BaseCard title="Técnica y protocolo">
    <a-row :gutter="16">
      <a-col :xs="24" :md="12">
        <a-form-item label="Técnica" required>
          <BaseSelect
            v-model="rootTechniqueId"
            :options="rootTechniqueOptions"
            :loading="loadingTechniques"
            placeholder="Seleccioná una técnica"
          />
        </a-form-item>
      </a-col>

      <a-col :xs="24" :md="12">
        <a-form-item
          label="Tipo de programa"
          :validate-status="errors.technique_id ? 'error' : ''"
          :help="errors.technique_id ?? ''"
          required
        >
          <BaseSelect
            v-model="subTechniqueId"
            :options="subTechniqueOptions"
            :disabled="!rootTechniqueId"
            placeholder="Seleccioná el tipo de programa"
          />
        </a-form-item>
      </a-col>
    </a-row>

    <a-form-item
      :label="protocolLabel"
      :validate-status="errors.protocol_id ? 'error' : ''"
      :help="errors.protocol_id ?? ''"
      required
    >
      <BaseSelect
        v-model="protocolId"
        :options="protocolOptions"
        :disabled="!subTechniqueId"
        :loading="loadingProtocols"
        :placeholder="`Seleccioná un ${protocolLabel.toLowerCase()}`"
      />
    </a-form-item>
  </BaseCard>
</template>
