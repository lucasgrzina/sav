<script setup lang="ts">
import { ref } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseDrawer from '@/components/atoms/overlays/BaseDrawer.vue'
import { useResponsive } from '@/core/composables/useResponsive'
import { PREVIEW_COPY } from '../../../constants/alert-copy'
import type { ProgramPreview } from '../../../types/program-alerts.types'
import type { SaveFlowStatus } from '../../../composables/useProgramSaveFlow'
import ProgramWarningList from '../alerts/ProgramWarningList.vue'
import ProgramMessageModal from './ProgramMessageModal.vue'
import ProgramPreviewDiff from './ProgramPreviewDiff.vue'
import ProgramPreviewError from './ProgramPreviewError.vue'
import ProgramPreviewGroups from './ProgramPreviewGroups.vue'
import ProgramPreviewSummary from './ProgramPreviewSummary.vue'

defineProps<{
  status: SaveFlowStatus
  preview: ProgramPreview | null
  requiresAcknowledgement: boolean
  canConfirm: boolean
  saving: boolean
}>()
const emit = defineEmits<{
  close: []
  retry: []
  confirm: []
  saveWithoutSummary: []
}>()

const open = defineModel<boolean>('open', { default: false })
const acknowledged = defineModel<boolean>('acknowledged', { default: false })

const { isMobile } = useResponsive()

const messageOpen = ref(false)
const message = ref<string | null>(null)

function showMessage(text: string) {
  message.value = text
  messageOpen.value = true
}
</script>

<template>
  <BaseDrawer v-model="open" :title="PREVIEW_COPY.title" :width="isMobile ? '100%' : 720" @close="emit('close')">
    <div v-if="status === 'loading'" class="ppp-loading" data-testid="preview-loading">
      <a-spin />
      <span>{{ PREVIEW_COPY.loading }}</span>
    </div>

    <ProgramPreviewError
      v-else-if="status === 'error'"
      :saving="saving"
      @retry="emit('retry')"
      @save-without-summary="emit('saveWithoutSummary')"
    />

    <template v-else-if="status === 'ready' && preview">
      <ProgramPreviewSummary :preview="preview" />
      <ProgramWarningList v-if="preview.warnings.length" :warnings="preview.warnings" class="ppp-warnings" />
      <ProgramPreviewDiff v-if="preview.diff" :diff="preview.diff" />
      <ProgramPreviewGroups :items="preview.items" @view-message="showMessage" />
    </template>

    <template #footer>
      <div class="ppp-footer">
        <a-checkbox v-if="status === 'ready' && requiresAcknowledgement" v-model:checked="acknowledged" data-testid="acknowledge">
          {{ PREVIEW_COPY.acknowledge }}
        </a-checkbox>
        <div class="ppp-buttons">
          <BaseButton variant="secondary" data-testid="back-to-edit" @click="emit('close')">
            {{ PREVIEW_COPY.backToEdit }}
          </BaseButton>
          <BaseButton
            v-if="status !== 'error'"
            variant="primary"
            :disabled="!canConfirm"
            :loading="saving"
            data-testid="confirm-save"
            @click="emit('confirm')"
          >
            {{ PREVIEW_COPY.confirm }}
          </BaseButton>
        </div>
      </div>
    </template>
  </BaseDrawer>
  <ProgramMessageModal v-model="messageOpen" :message="message" />
</template>

<style scoped>
.ppp-loading {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ppp-warnings {
  margin-bottom: 16px;
}

.ppp-footer {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ppp-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
</style>
