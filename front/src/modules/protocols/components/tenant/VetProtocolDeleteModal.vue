<script setup lang="ts">
import { computed } from 'vue'
import type { VetProtocolListItem } from '../../types/vet-protocol.types'
import type { ProtocolDeleteError } from '../../types/protocol.types'

const props = defineProps<{
  protocol: VetProtocolListItem | null
  isPending: boolean
  blockedError: ProtocolDeleteError | null
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const visible = defineModel<boolean>({ required: true })

const blockedMessage = computed(() => {
  if (!props.blockedError) return null
  const { count } = props.blockedError
  return `Este protocolo tiene ${count} programa(s) vinculado(s) y no puede eliminarse.`
})
</script>

<template>
  <a-modal
    :open="visible"
    :title="blockedError ? 'No se puede eliminar' : 'Eliminar protocolo'"
    :footer="null"
    @cancel="emit('cancel')"
  >
    <template v-if="blockedError">
      <a-alert type="error" :message="blockedMessage" show-icon class="vpdm-alert" />
      <p class="vpdm-hint">Para eliminar este protocolo, primero eliminá los programas vinculados.</p>
      <div class="vpdm-footer">
        <a-button @click="emit('cancel')">Cerrar</a-button>
      </div>
    </template>

    <template v-else>
      <p>
        ¿Estás seguro de que querés eliminar <strong>{{ protocol?.name }}</strong>?
      </p>
      <p class="vpdm-hint">
        Esta acción eliminará también todas sus tareas y alertas. No se puede deshacer.
      </p>
      <div class="vpdm-footer">
        <a-space>
          <a-button @click="emit('cancel')">Cancelar</a-button>
          <a-button type="primary" danger :loading="isPending" @click="emit('confirm')">
            Eliminar
          </a-button>
        </a-space>
      </div>
    </template>
  </a-modal>
</template>

<style scoped>
.vpdm-alert {
  margin-bottom: 12px;
}

.vpdm-hint {
  font-size: 13px;
  color: var(--dt-muted, #6b8cae);
  margin-bottom: 16px;
}

.vpdm-footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
