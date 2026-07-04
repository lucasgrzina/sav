<script setup lang="ts">
interface Props {
  direction?:
    | 'row'
    | 'column'

  justify?:
    | 'start'
    | 'center'
    | 'end'
}

withDefaults(
  defineProps<Props>(),
  {
    direction: 'row',
    justify: 'end'
  }
)
</script>

<template>
  <div
    :class="[
      'base-table-actions',

      `base-table-actions--${direction}`,

      `base-table-actions--${justify}`
    ]"
  >
    <slot />
  </div>
</template>

<style scoped>
.base-table-actions {
  @apply flex gap-2;
}

.base-table-actions--row {
  @apply flex-row items-center;
}

.base-table-actions--column {
  @apply flex-col;
}

.base-table-actions--start {
  @apply justify-start;
}

.base-table-actions--center {
  @apply justify-center;
}

.base-table-actions--end {
  @apply justify-end;
}
</style>