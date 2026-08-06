<script setup lang="ts">
import BaseCard from '@/components/atoms/cards/BaseCard.vue'

const comments = defineModel<string | null>('comments', { required: true })
</script>

<template>
  <BaseCard title="Otros datos">
    <a-form-item label="Comentarios">
      <a-textarea
        :value="comments ?? ''"
        :rows="3"
        placeholder="Comentarios opcionales"
        @update:value="(v: string) => (comments = v || null)"
      />
    </a-form-item>
  </BaseCard>
</template>
