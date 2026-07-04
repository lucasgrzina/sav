<script setup lang="ts">
import { computed } from 'vue'
import type { TechniqueListItem, TechniqueDeleteError } from '../types/technique.types'

const props = defineProps<{
  technique: TechniqueListItem | null
  isPending: boolean
  blockedError: TechniqueDeleteError | null
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const visible = defineModel<boolean>({ required: true })

const blockedMessage = computed(() => {
  if (!props.blockedError) return null
  const { reason, count } = props.blockedError
  if (reason === 'has_programs') {
    return `Esta técnica tiene ${count} programa(s) vinculado(s) y no puede eliminarse.`
  }
  if (reason === 'has_protocols') {
    return `Esta técnica tiene ${count} protocolo(s) vinculado(s) y no puede eliminarse.`
  }
  if (reason === 'children_have_programs') {
    return `Sub-técnicas de esta jerarquía tienen ${count} programa(s) vinculado(s). No se puede eliminar.`
  }
  return 'Esta técnica no puede eliminarse por tener elementos vinculados.'
})
</script>

<template>
  <a-modal
    :open="visible"
    :title="blockedError ? 'No se puede eliminar' : 'Eliminar técnica'"
    :footer="null"
    @cancel="emit('cancel')"
  >
    <template v-if="blockedError">
      <a-alert
        type="error"
        :message="blockedMessage"
        show-icon
        class="tdm-alert"
      />
      <p class="tdm-hint">
        Para eliminar esta técnica, primero eliminá los programas y protocolos vinculados.
      </p>
      <div class="tdm-footer">
        <a-button @click="emit('cancel')">Cerrar</a-button>
      </div>
    </template>

    <template v-else>
      <p>
        ¿Estás seguro de que querés eliminar <strong>{{ technique?.name }}</strong>?
      </p>
      <p class="tdm-hint">
        Esta acción eliminará también todas sus sub-técnicas. No se puede deshacer.
      </p>
      <div class="tdm-footer">
        <a-space>
          <a-button @click="emit('cancel')">Cancelar</a-button>
          <a-button
            type="primary"
            danger
            :loading="isPending"
            @click="emit('confirm')"
          >
            Eliminar
          </a-button>
        </a-space>
      </div>
    </template>
  </a-modal>
</template>

<style scoped>
.tdm-alert {
  margin-bottom: 12px;
}

.tdm-hint {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  margin-bottom: 16px;
}

.tdm-footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
