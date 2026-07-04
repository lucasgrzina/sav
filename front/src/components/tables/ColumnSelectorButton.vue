<script setup lang="ts">
import { AppstoreOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

defineOptions({ inheritAttrs: false })
</script>

<template>
  <BaseButton variant="tertiary" v-bind="$attrs">
    <template #icon><AppstoreOutlined /></template>
    Columnas
  </BaseButton>
</template>
