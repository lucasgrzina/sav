<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  width?: number | string
  destroyOnClose?: boolean
}>(), {
  width: 520,
  destroyOnClose: true,
})

const isOpen = defineModel<boolean>({ default: false })
const emit = defineEmits<{ cancel: [] }>()
</script>

<template>
  <a-modal
    v-model:open="isOpen"
    :title="title"
    :width="width"
    :footer="$slots.footer ? undefined : null"
    :destroy-on-close="destroyOnClose"
    @cancel="emit('cancel')"
  >
    <template v-if="$slots.header" #title>
      <slot name="header" />
    </template>
    <slot />
    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>
  </a-modal>
</template>
