<script setup lang="ts">
interface Props {
  label?: string
  error?: string
}

defineProps<Props>()
</script>

<template>
  <div class="flex flex-col gap-1 form-field-wrapper">
    <label
      v-if="label"
      class="text-xs font-medium"
      :class="{'danger': error}"
    >
      {{ label }}
    </label>

    <slot />

    <span
      v-if="error"
      class="text-sm danger"
    >
      {{ error }}
    </span>
  </div>
</template>
<style lang="scss" scoped>
.form-field-wrapper {

    label {
        color: var(--dt-muted, #6B8CAE);
    }

    .danger {
            color: var(--dt-danger);        
    }
}



</style>