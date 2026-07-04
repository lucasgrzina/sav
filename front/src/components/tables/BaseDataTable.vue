<script
  setup
  lang="ts"
  generic="T extends Record<string, any>"
>
import {
  computed,
  useAttrs
} from 'vue'

import {
  Table as ATable
} from 'ant-design-vue'

import type {
  TableProps
} from 'ant-design-vue'

defineOptions({
  inheritAttrs: false
})

/**
 * Props propias opcionales
 * del design system
 */
interface BaseDataTableProps
  extends /* @vue-ignore */ TableProps<T> {
  rounded?: boolean
}

/**
 * Props
 */
const props = withDefaults(
  defineProps<BaseDataTableProps>(),
  {
    rounded: true
  }
)

/**
 * Hereda:
 * - eventos
 * - attrs
 * - listeners
 * de a-table
 */
const attrs = useAttrs()

/**
 * Clases internas
 */
const classes = computed(() => [
  'base-data-table',

  {
    'base-data-table--rounded':
      props.rounded
  }
])
</script>

<template>
  <div :class="classes">
    <ATable
      v-bind="props"
      v-bind.attrs="attrs"
    >
      <!-- Forward ALL slots -->
      <template
        v-for="(_, slot) in $slots"
        #[slot]="scope"
      >
        <slot
          :name="slot"
          v-bind="scope"
        />
      </template>
    </ATable>
  </div>
</template>

<style scoped>
.base-data-table {
  @apply overflow-hidden;
  background:    var(--dt-card, #0E2038);
  border:        1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: var(--dt-radius-xl, 16px);
  transition:    background 0.25s ease, border-color 0.25s ease;
}

.base-data-table--rounded {
  border-radius: var(--dt-radius-xl, 16px);
}
</style>