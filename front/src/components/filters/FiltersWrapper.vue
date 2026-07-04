<script setup lang="ts">
interface Props {
  label?: string
}

defineProps<Props>()
</script>

<template>
  <div class="flex flex-col gap-1 form-field-wrapper">
    <label
      v-if="label"
      class="text-xs font-medium"
    >
      {{ label }}
    </label>

    <slot />

  </div>
</template>
<style lang="scss" scoped>
.form-field-wrapper {

    label {
        color: var(--dt-muted, #6B8CAE);
    }

}
</style>