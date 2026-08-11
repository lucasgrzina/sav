<script setup lang="ts">
import { ref, toRef, watch } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import RoleChip from '@/components/atoms/display/RoleChip.vue'
import { useProgramShare } from '../../composables/useProgramShare'

const props = defineProps<{
  programGuid: string | null
}>()

const emit = defineEmits<{
  sent: []
}>()

const isOpen = defineModel<boolean>('open', { default: false })

const programGuidRef = toRef(props, 'programGuid')
const { recipients, isLoadingRecipients, share, isSharing } = useProgramShare(programGuidRef, isOpen)

const selectedGuids = ref<string[]>([])

// Limpia la selección cada vez que el modal se cierra, evitando estado
// residual entre distintos programas.
watch(isOpen, (open) => {
  if (!open) selectedGuids.value = []
})

function onToggle(guid: string, checked: boolean) {
  selectedGuids.value = checked
    ? [...selectedGuids.value, guid]
    : selectedGuids.value.filter((v) => v !== guid)
}

function onConfirm() {
  share(selectedGuids.value, {
    onSuccess: () => emit('sent'),
  })
}
</script>

<template>
  <BaseModal v-model="isOpen" title="Enviar programa por WhatsApp" :width="480">
    <a-spin v-if="isLoadingRecipients" size="small" />
    <div v-else-if="!recipients.length" class="psm-empty">
      No hay destinatarios disponibles del lado del cliente.
    </div>
    <div v-else class="psm-list">
      <a-checkbox
        v-for="recipient in recipients"
        :key="recipient.guid"
        :checked="selectedGuids.includes(recipient.guid)"
        :disabled="!recipient.has_whatsapp"
        @change="(e: { target: { checked: boolean } }) => onToggle(recipient.guid, e.target.checked)"
      >
        <span class="psm-option">
          {{ recipient.name }}
          <RoleChip :role="recipient.role" />
          <a-tag v-if="!recipient.has_whatsapp" color="default">Sin WhatsApp</a-tag>
        </span>
      </a-checkbox>
    </div>

    <template #footer>
      <BaseButton variant="secondary" :disabled="isSharing" @click="isOpen = false">
        Cancelar
      </BaseButton>
      <BaseButton
        variant="primary"
        :loading="isSharing"
        :disabled="!selectedGuids.length || isSharing"
        @click="onConfirm"
      >
        Enviar
      </BaseButton>
    </template>
  </BaseModal>
</template>

<style scoped>
.psm-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.psm-option {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.psm-empty {
  color: var(--dt-text-secondary, rgba(255, 255, 255, 0.45));
  font-size: 13px;
}
</style>
