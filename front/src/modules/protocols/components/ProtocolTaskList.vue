<script setup lang="ts">
import { PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import ProtocolTaskFormItem from './ProtocolTaskFormItem.vue'
import type { ProtocolTaskFormValues } from '../validators/protocol.validator'

const tasks = defineModel<ProtocolTaskFormValues[]>({ required: true })

function emptyTask(): ProtocolTaskFormValues {
  return {
    description: '',
    days_offset: 0,
    time_of_day: 'before',
    time: '',
    important: false,
    alerts: [],
  }
}

function addTask() {
  tasks.value = [...tasks.value, emptyTask()]
}

function removeTask(index: number) {
  tasks.value = tasks.value.filter((_, i) => i !== index)
}

function updateTask(index: number, value: ProtocolTaskFormValues) {
  const updated = [...tasks.value]
  updated[index] = value
  tasks.value = updated
}

function moveUp(index: number) {
  if (index <= 0) return
  const updated = [...tasks.value]
  ;[updated[index - 1], updated[index]] = [updated[index], updated[index - 1]]
  tasks.value = updated
}

function moveDown(index: number) {
  if (index >= tasks.value.length - 1) return
  const updated = [...tasks.value]
  ;[updated[index], updated[index + 1]] = [updated[index + 1], updated[index]]
  tasks.value = updated
}
</script>

<template>
  <div class="ptl-root">
    <p v-if="tasks.length === 0" class="ptl-empty">Sin tareas para este protocolo.</p>

    <ProtocolTaskFormItem
      v-for="(task, idx) in tasks"
      :key="task.guid ?? `new-task-${idx}`"
      :task="task"
      :index="idx"
      :is-first="idx === 0"
      :is-last="idx === tasks.length - 1"
      @update="updateTask"
      @remove="removeTask"
      @move-up="moveUp"
      @move-down="moveDown"
    />

    <BaseButton variant="secondary" size="small" @click="addTask">
      <template #icon><PlusOutlined /></template>
      Agregar tarea
    </BaseButton>
  </div>
</template>

<style scoped>
.ptl-root {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ptl-empty {
  font-size: 13px;
  color: var(--dt-muted, #6b8cae);
  margin: 0;
}
</style>
