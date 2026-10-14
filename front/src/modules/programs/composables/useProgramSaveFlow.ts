import { computed, ref, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { useRouter } from 'vue-router'
import { useNotification } from '@/core/composables/useNotification'
import { PREVIEW_COPY } from '../constants/alert-copy'
import { useCreateProgram, useUpdateProgram } from './useProgramMutations'
import { useProgramPreview } from './useProgramPreview'
import type { CreateProgramPayload, ProgramDetail } from '../types/program.types'
import type { PreviewProgramPayload, ProgramPreview } from '../types/program-alerts.types'

export type SaveFlowStatus = 'idle' | 'loading' | 'ready' | 'error'

// Orchestrates save -> preview -> confirm. The server never depends on the preview to save
// (DU-01): "saveWithoutPreview" goes straight to store/update.
export function useProgramSaveFlow(
  vetGuid: MaybeRefOrGetter<string>,
  editingGuid: MaybeRefOrGetter<string | null>,
  onSavedWithoutCritical: () => void,
) {
  const router = useRouter()
  const { warning } = useNotification()
  const preview = useProgramPreview(vetGuid)
  const create = useCreateProgram()
  const update = useUpdateProgram()

  const open = ref(false)
  const status = ref<SaveFlowStatus>('idle')
  const result = ref<ProgramPreview | null>(null)
  const acknowledged = ref(false)
  const pendingPayload = ref<CreateProgramPayload | null>(null)

  const requiresAcknowledgement = computed(
    () => result.value?.warnings.some((w) => w.severity === 'critical') ?? false,
  )
  const canConfirm = computed(
    () => status.value === 'ready' && (!requiresAcknowledgement.value || acknowledged.value),
  )
  const isSaving = computed(() => create.isPending.value || update.isPending.value)
  const fieldErrors = computed(() => (toValue(editingGuid) ? update.fieldErrors.value : create.fieldErrors.value))

  function toPreviewPayload(payload: CreateProgramPayload): PreviewProgramPayload {
    return {
      client_id: payload.client_id,
      establishment_id: payload.establishment_id,
      protocol_id: payload.protocol_id,
      // Groups without guid are identified by their position in the form.
      targets: payload.targets.map((t, index) => ({
        ...(t.guid ? { guid: t.guid } : {}),
        target_ref: String(index),
        target_date: t.target_date,
      })),
      manager_profile_ids: payload.manager_profile_ids,
    }
  }

  async function runPreview() {
    if (!pendingPayload.value) return
    status.value = 'loading'
    result.value = null
    acknowledged.value = false
    try {
      result.value = await preview.mutateAsync({
        payload: toPreviewPayload(pendingPayload.value),
        guid: toValue(editingGuid),
      })
      status.value = 'ready'
    } catch {
      status.value = 'error'
    }
  }

  function start(payload: CreateProgramPayload) {
    pendingPayload.value = payload
    open.value = true
    return runPreview()
  }

  function close() {
    open.value = false
    status.value = 'idle'
    result.value = null
    acknowledged.value = false
  }

  // The store/update response is authoritative: critical warnings there send the user to the tab.
  function handleSaved(saved: ProgramDetail) {
    const hasCritical = saved.warnings?.some((w) => w.severity === 'critical') ?? false
    close()
    if (hasCritical && saved.guid) {
      warning(PREVIEW_COPY.savedWithCritical)
      router.push({
        name: 'vet-programs-detail',
        params: { vetGuid: toValue(vetGuid), guid: saved.guid },
        query: { tab: 'alerts' },
      })
      return
    }
    onSavedWithoutCritical()
  }

  function save() {
    const payload = pendingPayload.value
    if (!payload) return
    const guid = toValue(editingGuid)
    const options = {
      onSuccess: handleSaved,
      // Validation errors are shown on the form fields: get the panel out of the way.
      onError: () => close(),
    }
    if (guid) update.mutate({ guid, payload }, options)
    else create.mutate(payload, options)
  }

  function confirmAndSave() {
    if (!canConfirm.value) return
    save()
  }

  function saveWithoutPreview() {
    save()
  }

  return {
    open,
    status,
    preview: result,
    acknowledged,
    requiresAcknowledgement,
    canConfirm,
    isSaving,
    fieldErrors,
    start,
    retry: runPreview,
    close,
    confirmAndSave,
    saveWithoutPreview,
  }
}
