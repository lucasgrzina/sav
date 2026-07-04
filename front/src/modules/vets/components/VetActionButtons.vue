<script setup lang="ts">
import { computed } from 'vue'
import { getVetStatus } from '../api/vets.mapper'
import { useValidateVet } from '../composables/useValidateVet'
import { useSuspendVet } from '../composables/useSuspendVet'
import { useUnsuspendVet } from '../composables/useUnsuspendVet'
import type { VetItem } from '../types/vet.types'

const props = defineProps<{ vet: VetItem, size: 'small' | 'middle' | 'large' }>()

const status = computed(() => getVetStatus(props.vet))

const { validateVet, isPending: isValidating } = useValidateVet()
const { suspendVet, isPending: isSuspending } = useSuspendVet()
const { unsuspendVet, isPending: isUnsuspending } = useUnsuspendVet()

const isLoading = computed(() => isValidating.value || isSuspending.value || isUnsuspending.value)
</script>

<template>
  <PermissionGuard permission="vets.validate">
    <a-space>
      <BaseButton
        v-if="status === 'pending'"
        variant="tertiary"
        :size="props.size"
        :loading="isLoading"
        @click="validateVet(vet)"
      >
        Validar
      </BaseButton>

      <BaseButton
        v-else-if="status === 'active'"
        variant="tertiary"
        :size="props.size"
        :loading="isLoading"
        @click="suspendVet(vet)"
      >
        Suspender
      </BaseButton>

      <BaseButton
        v-else-if="status === 'suspended'"
        :size="props.size"
        variant="tertiary"
        :loading="isLoading"
        @click="unsuspendVet(vet)"
      >
        Reactivar
      </BaseButton>
    </a-space>
  </PermissionGuard>
</template>
