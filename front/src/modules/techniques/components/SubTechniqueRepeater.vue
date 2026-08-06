<script setup lang="ts">
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import type { TechniqueChild } from '../types/technique.types'

const children = defineModel<TechniqueChild[]>({ required: true })

function addChild() {
  children.value = [...children.value, { name: '', protocols_name: null, target_date_name: null }]
}

function removeChild(index: number) {
  children.value = children.value.filter((_, i) => i !== index)
}

function updateName(index: number, value: string) {
  const updated = [...children.value]
  updated[index] = { ...updated[index], name: value }
  children.value = updated
}

function updateProtocolsName(index: number, value: string) {
  const updated = [...children.value]
  updated[index] = { ...updated[index], protocols_name: value || null }
  children.value = updated
}
</script>

<template>
  <div class="str-root">
    <p v-if="children.length === 0" class="str-empty">
      Sin sub-técnicas. Podés agregar hasta 50.
    </p>

    <div
      v-for="(child, idx) in children"
      :key="child.guid ?? `new-${idx}`"
      class="str-row"
    >
      <a-input
        :value="child.name"
        placeholder="Nombre de la sub-técnica *"
        @update:value="(v: string) => updateName(idx, v)"
      />
      <a-input
        :value="child.protocols_name ?? ''"
        placeholder="Label selector de protocolos (opcional)"
        @update:value="(v: string) => updateProtocolsName(idx, v)"
      />
      <BaseButton
        variant="row-action"
        size="small"
        danger
        tooltip="Eliminar sub-técnica"
        @click="removeChild(idx)"
      >
        <template #icon><DeleteOutlined /></template>
      </BaseButton>
    </div>

    <div class="str-add">
      <BaseButton variant="secondary" size="small" @click="addChild">
        <template #icon><PlusOutlined /></template>
        Agregar sub-técnica
      </BaseButton>
    </div>
  </div>
</template>

<style scoped>
.str-root {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.str-empty {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  margin: 0;
}

.str-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.str-row :deep(.ant-input) {
  flex: 1;
}

.str-add {
  margin-top: 4px;
}
</style>
