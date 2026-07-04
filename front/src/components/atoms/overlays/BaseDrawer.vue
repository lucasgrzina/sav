<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  width?: number | string
  placement?: 'right' | 'left' | 'top' | 'bottom'
}>(), {
  width: 480,
  placement: 'right',
})

const isOpen = defineModel<boolean>({ default: false })
const emit = defineEmits<{ close: [] }>()
</script>

<template>
  <a-drawer
    v-model:open="isOpen"
    :title="title"
    :width="width"
    :placement="placement"
    class="base-drawer"
    @close="emit('close')"
  >
    <slot />
    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>
  </a-drawer>
</template>

<style scoped>
:deep(.base-drawer .ant-drawer-content) {
  background-color: #fff;
}
</style>
