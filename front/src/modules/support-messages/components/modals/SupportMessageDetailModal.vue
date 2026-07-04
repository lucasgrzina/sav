<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { LockOutlined } from '@ant-design/icons-vue'
import { useQuery } from '@tanstack/vue-query'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import BaseRichTextEditor from '@/components/atoms/inputs/BaseRichTextEditor.vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import SupportMessageStatusBadge from '../SupportMessageStatusBadge.vue'
import { formatDate } from '@/core/utils/date'
import { useAddReply } from '../../composables/useAddReply'
import { useCloseThread } from '../../composables/useCloseThread'
import { usePermission } from '@/core/composables/usePermissions'
import { useSanitize } from '@/core/composables/useSanitize'
import { useAuthStore } from '@/modules/auth/stores/auth.store'
import { PERMISSIONS } from '@/core/constants/permissions'
import { getSupportMessageApi } from '../../api/support-messages.api'
import { addReplySchema } from '../../validators/support-message.validator'
import type { SupportMessage, SupportMessagePriority, SupportMessageCategory } from '../../types/support-message.types'

const props = defineProps<{ message: SupportMessage | null }>()
const isOpen = defineModel<boolean>({ default: false })

const auth = useAuthStore()
const { can } = usePermission()
const { sanitize } = useSanitize()

const replyBody = ref('')
const replyError = ref<string | null>(null)
const replyFieldErrors = ref<Record<string, string> | null>(null)

const { mutate: mutateReply, isPending: isReplying, fieldErrors, generalError, resetErrors } = useAddReply()
const { mutate: mutateClose, isPending: isClosing } = useCloseThread()

const { data: thread, isLoading: isLoadingThread, refetch } = useQuery({
  queryKey: computed(() => ['support-messages', props.message?.guid]),
  queryFn: () => getSupportMessageApi(props.message!.guid),
  enabled: computed(() => isOpen.value && !!props.message?.guid),
  staleTime: 0,
})

const canReply = computed(() => {
  if (!thread.value) return false
  const isOwner = thread.value.sender?.guid === auth.user?.guid
  return isOwner || can(PERMISSIONS.SUPPORT_MESSAGES_REPLY)
})

watch(isOpen, (open) => {
  if (open) {
    replyBody.value = ''
    replyError.value = null
    replyFieldErrors.value = null
    resetErrors()
  }
})

watch(fieldErrors, (errs) => { replyFieldErrors.value = errs })
watch(generalError, (msg) => { replyError.value = msg })

function isCurrentUser(authorGuid: string): boolean {
  return auth.user?.guid === authorGuid
}

function clearBodyError() {
  if (replyFieldErrors.value?.body) {
    replyFieldErrors.value = { ...replyFieldErrors.value, body: '' }
  }
}

function submitReply() {
  const result = addReplySchema.safeParse({ body: replyBody.value })
  if (!result.success) {
    replyFieldErrors.value = { body: result.error.errors[0]?.message ?? 'Mensaje requerido' }
    return
  }

  mutateReply(
    { guid: props.message!.guid, payload: { body: replyBody.value } },
    {
      onSuccess: () => {
        replyBody.value = ''
        replyFieldErrors.value = null
        refetch()
      },
    },
  )
}

function handleClose() {
  mutateClose(props.message!.guid, {
    onSuccess: () => {
      refetch()
    },
  })
}

function priorityColor(priority: SupportMessagePriority): string {
  return { low: 'default', medium: 'gold', high: 'red' }[priority]
}

function priorityLabel(priority: SupportMessagePriority): string {
  return { low: 'Baja', medium: 'Media', high: 'Alta' }[priority]
}

function categoryLabel(category: SupportMessageCategory): string {
  return {
    bug: 'Error / Bug',
    question: 'Consulta',
    feature_request: 'Solicitud de mejora',
    other: 'Otro',
  }[category]
}
</script>

<template>
  <BaseModal v-model="isOpen" :title="message?.subject ?? 'Detalle'" :width="720">
    <template v-if="thread">
      <!-- Metadatos del hilo -->
      <div class="flex flex-wrap gap-2 items-center mb-4">
        <SupportMessageStatusBadge :status="thread.status" />
        <a-tag :color="priorityColor(thread.priority)">{{ priorityLabel(thread.priority) }}</a-tag>
        <a-tag>{{ categoryLabel(thread.category) }}</a-tag>
        <span class="text-xs text-gray-400 ml-auto">{{ formatDate(thread.created_at) }}</span>
      </div>

      <!-- Lista de mensajes del hilo -->
      <div class="thread-messages space-y-3 mb-4 max-h-96 overflow-y-auto pr-1">
        <!-- Mensaje inicial del usuario -->
        <div class="thread-item thread-item--user">
          <div class="thread-item__header">
            <span class="thread-item__author">{{ thread.sender?.name ?? 'Usuario' }}</span>
            <span class="thread-item__time">{{ formatDate(thread.created_at) }}</span>
          </div>
          <div class="thread-item__body rich-content" v-html="sanitize(thread.body)" />
        </div>

        <!-- Respuestas del hilo -->
        <div
          v-for="reply in thread.replies"
          :key="reply.guid"
          class="thread-item"
          :class="isCurrentUser(reply.author.guid) ? 'thread-item--user' : 'thread-item--support'"
        >
          <div class="thread-item__header">
            <span class="thread-item__author">{{ reply.author.name }}</span>
            <span class="thread-item__time">{{ formatDate(reply.created_at) }}</span>
          </div>
          <div class="thread-item__body rich-content" v-html="sanitize(reply.body)" />
        </div>

        <!-- Estado cerrado -->
        <div v-if="thread.status === 'closed'" class="thread-closed">
          <LockOutlined />
          <span>
            Cerrado el {{ formatDate(thread.closed_at) }}
            <template v-if="thread.closer"> por {{ thread.closer.name }}</template>
          </span>
        </div>
      </div>

      <!-- Formulario de reply -->
      <template v-if="thread.status !== 'closed' && canReply">
        <a-divider style="margin: 12px 0" />
        <a-alert
          v-if="replyError"
          :message="replyError"
          type="error"
          show-icon
          closable
          style="margin-bottom: 12px"
          @close="replyError = null"
        />
        <a-form layout="vertical" @submit.prevent="submitReply">
          <a-form-item
            :validate-status="replyFieldErrors?.body ? 'error' : ''"
            :help="replyFieldErrors?.body ?? ''"
            style="margin-bottom: 8px"
          >
            <BaseRichTextEditor
              v-model="replyBody"
              placeholder="Escribí tu respuesta..."
              :has-error="!!replyFieldErrors?.body"
              @blur="clearBodyError"
            />
          </a-form-item>
          <div class="flex justify-between items-center">
            <PermissionGuard :permission="PERMISSIONS.SUPPORT_MESSAGES_CLOSE">
              <a-popconfirm
                title="¿Cerrar este hilo de soporte?"
                ok-text="Sí, cerrar"
                cancel-text="No"
                @confirm="handleClose"
              >
                <BaseButton variant="secondary" danger :loading="isClosing">Cerrar hilo</BaseButton>
              </a-popconfirm>
            </PermissionGuard>
            <BaseButton
              variant="primary"
              html-type="submit"
              :loading="isReplying"
            >
              Responder
            </BaseButton>
          </div>
        </a-form>
      </template>

      <!-- Solo botón cerrar si no puede responder pero sí cerrar -->
      <template v-else-if="thread.status !== 'closed' && !canReply">
        <PermissionGuard :permission="PERMISSIONS.SUPPORT_MESSAGES_CLOSE">
          <a-divider style="margin: 12px 0" />
          <div class="flex justify-end">
            <a-popconfirm
              title="¿Cerrar este hilo de soporte?"
              ok-text="Sí, cerrar"
              cancel-text="No"
              @confirm="handleClose"
            >
              <BaseButton variant="secondary" danger :loading="isClosing">Cerrar hilo</BaseButton>
            </a-popconfirm>
          </div>
        </PermissionGuard>
      </template>
    </template>

    <template v-else-if="isLoadingThread">
      <div class="flex justify-center py-8">
        <a-spin />
      </div>
    </template>

    <template #footer>
      <BaseButton variant="secondary" @click="isOpen = false">Cerrar</BaseButton>
    </template>
  </BaseModal>
</template>

<style scoped>
.thread-messages { scrollbar-width: thin; }

.thread-item {
  border-radius: 8px;
  padding: 10px 14px;
  background: #f9f9f9;
  border: 1px solid #e8e8e8;
}

.thread-item--support {
  background: #e6f4ff;
  border-color: #91caff;
  margin-left: 24px;
}

.thread-item--user {
  margin-right: 24px;
}

.thread-item__header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 6px;
}

.thread-item__author {
  font-weight: 600;
  font-size: 13px;
}

.thread-item__time {
  font-size: 11px;
  color: #999;
}

.thread-item__body { font-size: 13px; }

.thread-closed {
  display: flex;
  align-items: center;
  gap: 6px;
  justify-content: center;
  color: #999;
  font-size: 12px;
  padding: 8px;
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
}

.rich-content :deep(p) { margin: 0 0 4px; }
.rich-content :deep(p:last-child) { margin-bottom: 0; }
.rich-content :deep(ul),
.rich-content :deep(ol) { padding-left: 20px; margin: 4px 0; }
</style>
