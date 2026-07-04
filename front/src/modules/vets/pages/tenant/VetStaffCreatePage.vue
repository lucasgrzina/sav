<script setup lang="ts">
import { useRouter, useRoute } from 'vue-router'
import { ArrowLeftOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import VetStaffLookupForm from '@/modules/users/components/forms/VetStaffLookupForm.vue'

const router  = useRouter()
const route   = useRoute()
const vetGuid = () => route.params.vetGuid as string

function handleSuccess(): void {
  router.push(`/vets/${vetGuid()}/usuarios`)
}
</script>

<template>
  <div>
    <div class="ccp-header">
      <BaseButton variant="tertiary" @click="router.push(`/vets/${vetGuid()}/usuarios`)">
        <template #icon><ArrowLeftOutlined /></template>
        Volver a usuarios
      </BaseButton>
    </div>

    <AppHeader
      title="Incorporar personal al equipo"
      subtitle="Buscá el usuario por email para incorporarlo, o completá los datos para crear uno nuevo."
      size="default"
    />

    <VetStaffLookupForm @success="handleSuccess" />
  </div>
</template>

<style scoped>
.ccp-header { margin-bottom: 16px; }

.ccp-back {
  background: none;
  border: none;
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  transition: color 0.15s;
}
.ccp-back:hover { color: var(--dt-accent, #1AE5A0); }
</style>
