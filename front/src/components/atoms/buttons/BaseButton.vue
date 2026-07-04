<script setup lang="ts">
import { computed, useAttrs } from 'vue'
import { Button as AButton, Tooltip as ATooltip } from 'ant-design-vue'
import type { ButtonProps } from 'ant-design-vue'

defineOptions({ inheritAttrs: false })

export type BaseButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'row-action'

interface Props extends /* @vue-ignore */ ButtonProps {
  variant?: BaseButtonVariant
  tooltip?: string
  tooltipPlacement?:
    | 'top' | 'left' | 'right' | 'bottom'
    | 'topLeft' | 'topRight' | 'bottomLeft' | 'bottomRight'
    | 'leftTop' | 'leftBottom' | 'rightTop' | 'rightBottom'
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  tooltipPlacement: 'top',
})

const attrs = useAttrs()

const antType = computed(() => {
  switch (props.variant) {
    case 'primary':   return 'primary'
    case 'secondary': return 'default'
    case 'tertiary':
    case 'row-action': return 'text'
    default:          return 'default'
  }
})

const classes = computed(() => [
  'base-button',
  `base-button--${props.variant}`,
  {
    'base-button--block':   attrs.block,
    'base-button--danger':  attrs.danger,
    'base-button--loading': attrs.loading,
  },
])

const hasTooltip = computed(() => !!props.tooltip)
</script>

<template>
  <template v-if="hasTooltip">
    <ATooltip :title="tooltip" :placement="tooltipPlacement">
      <AButton v-bind="attrs" :type="antType" :class="classes">
        <template v-if="$slots.icon" #icon><slot name="icon" /></template>
        <slot />
      </AButton>
    </ATooltip>
  </template>

  <template v-else>
    <AButton v-bind="attrs" :type="antType" :class="classes">
      <template v-if="$slots.icon" #icon><slot name="icon" /></template>
      <slot />
    </AButton>
  </template>
</template>

<style scoped>
.base-button {
  @apply inline-flex items-center justify-center gap-2
         rounded-lg font-medium
         transition-all duration-200
         shadow-none
         focus:outline-none;
  border-width: 2px;
}

/* =========================================================
   PRIMARY
========================================================= */

.base-button--primary {
  @apply border-transparent;
}

.base-button--primary:not(:disabled):hover {
  @apply opacity-90;
}

/* =========================================================
   SECONDARY
========================================================= */

.base-button--secondary:not(:disabled) {
  @apply bg-transparent;
  color: var(--dt-accent-t);
  border-color: var(--dt-border2);
}

.base-button--secondary:not(:disabled):hover {
  background:   var(--dt-accent-bg);
  color:        var(--dt-accent-t);
  border-color: var(--dt-border2);
}

/* =========================================================
   TERTIARY
========================================================= */

.base-button--tertiary:not(:disabled) {
  @apply border-transparent bg-transparent shadow-none;
  color: var(--dt-accent-t);
}

.base-button--tertiary:not(:disabled):hover {
  @apply border-transparent;
  background: var(--dt-accent-bg);
  color:      var(--dt-accent-t);
}

/* =========================================================
   ROW ACTION
========================================================= */

.base-button--row-action:not(:disabled) {
  @apply border-transparent bg-transparent shadow-none;
  color: var(--dt-muted);
}

.base-button--row-action:not(:disabled):hover {
  @apply border-transparent;
  background: var(--dt-hover);
  color:      var(--dt-text);
}


/* =========================================================
   BLOCK
========================================================= */

.base-button--block {
  @apply w-full;
}

/* =========================================================
   DANGER
========================================================= */

.base-button--danger.base-button--secondary {
  @apply border-red-500 text-red-500;
}

.base-button--danger.base-button--row-action:not(:disabled):hover {
  background: rgba(255, 90, 106, 0.1);
  color:      #FF5A6A;
}

/* =========================================================
   LOADING
========================================================= */

.base-button--loading {
  @apply cursor-wait;
}
</style>