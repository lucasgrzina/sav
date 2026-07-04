<script setup lang="ts">
import type { SizeType } from 'ant-design-vue/es/config-provider'
import { computed } from 'vue'

export type AppHeaderSize =
  | 'large'
  | 'default'
  | 'small'

interface Props {
  title: string
  subtitle?: string
  size?: AppHeaderSize
  bordered?: boolean
}

const props = withDefaults(
  defineProps<Props>(),
  {
    size: 'large',
    bordered: false
  }
)

const classes = computed(() => [
  'app-header',
  `app-header--${props.size}`,
  { 'app-header--bordered': props.bordered }
])

const actionsButtonSize = computed(() => {
    return props.size as SizeType
})
</script>

<template>
  <header :class="classes">
    <div class="app-header__content">
      <div class="app-header__text">
        <h1 class="app-header__title">
          {{ title }}
        </h1>
        <p v-if="subtitle" class="app-header__subtitle">
          {{ subtitle }}
        </p>
      </div>
    </div>
    <div v-if="$slots.actions" class="app-header__actions">
      <slot name="actions" :button-size="actionsButtonSize" />
    </div>
  </header>
</template>

<style scoped>
.app-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
}

.app-header__text {
  @apply flex flex-col gap-1;
}

.app-header__title {
  @apply m-0 font-bold;
  font-family:    'Syne', sans-serif;
  letter-spacing: -0.02em;
  line-height:    1.2;
  color:          var(--dt-title, #fff);
}

.app-header__subtitle {
  @apply m-0;
  line-height: 1.5;
  color: var(--dt-muted, #6B8CAE);
}

.app-header__actions {
  @apply flex flex-wrap items-center gap-2;
}

.app-header--large   .app-header__title    { font-size: 24px; }
.app-header--large   .app-header__subtitle { @apply text-sm; }
.app-header--default .app-header__title    { font-size: 20px; }
.app-header--default .app-header__subtitle { @apply text-sm; }
.app-header--small   .app-header__title    { font-size: 16px; }
.app-header--small   .app-header__subtitle { @apply text-xs; }

.app-header--bordered {
  border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  @apply pb-4;
}
</style>
