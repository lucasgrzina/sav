<script setup lang="ts">
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { SettingDefinition } from '../config/settings.registry'
import type { SettingValue } from '../types/settings.types'

defineProps<{
  active:   SettingDefinition | null
  isSaving: boolean
}>()

const isOpen     = defineModel<boolean>('isOpen',      { default: false })
const draftValue = defineModel<SettingValue>('draftValue', { default: '' })

const emit = defineEmits<{ close: []; save: [] }>()

function close() { emit('close') }
function save()  { emit('save') }
</script>

<template>
  <BaseModal v-model="isOpen" :title="active?.label ?? 'Editar configuración'" :width="440" @cancel="close">
    <div v-if="active" class="sem-body">
      <a-select
        v-model:value="draftValue"
        class="sem-select"
        :options="active.options"
      >
        <template #option="opt">
          <div class="sem-option">
            <span v-if="opt.color" class="sem-swatch" :style="{ background: opt.color }" />
            {{ opt.label }}
          </div>
        </template>
      </a-select>

      <div class="sem-footer">
        <BaseButton variant="secondary" @click="close">Cancelar</BaseButton>
        <BaseButton variant="primary" :loading="isSaving" @click="save">Guardar</BaseButton>
      </div>
    </div>
  </BaseModal>
</template>

<style scoped>
.sem-body   { display: flex; flex-direction: column; gap: 20px; padding-top: 4px; }
.sem-select { width: 100%; }

.sem-option {
  display:     flex;
  align-items: center;
  gap:         8px;
}

.sem-swatch {
  display:       inline-block;
  width:         10px;
  height:        10px;
  border-radius: 50%;
  flex-shrink:   0;
}

.sem-footer {
  display:         flex;
  justify-content: flex-end;
  gap:             8px;
  padding-top:     4px;
}
</style>
