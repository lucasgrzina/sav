<script setup lang="ts">
import { ExclamationCircleOutlined } from '@ant-design/icons-vue'
import RoleChip from '@/components/atoms/display/RoleChip.vue'
import type { ProtocolTask, ProtocolTaskAlert } from '../../types/protocol.types'

defineProps<{ tasks: ProtocolTask[] }>()

// Un protocolo no tiene fecha ancla propia (esa la aporta la sub-técnica al crear un
// programa) — por eso acá se muestra el offset relativo (días antes/después) en vez de
// una fecha calculada, a diferencia de ProgramTargetsTimeline.
function formatOffset(days: number, timeOfDay: 'before' | 'after', time: string): string {
  const dayLabel = days === 0 ? 'El mismo día' : `${days} día${days === 1 ? '' : 's'} ${timeOfDay === 'before' ? 'antes' : 'después'}`
  return time ? `${dayLabel} · ${time}` : dayLabel
}

function groupAlertRoles(alert: ProtocolTaskAlert): string[] {
  return alert.roles
}
</script>

<template>
  <div class="ptt-root">
    <a-empty v-if="!tasks.length" description="Este protocolo no tiene tareas." />

    <a-timeline v-else class="ptt-timeline">
      <a-timeline-item
        v-for="task in tasks"
        :key="task.guid"
        :color="task.important ? 'orange' : 'blue'"
      >
        <template v-if="task.important" #dot>
          <ExclamationCircleOutlined style="font-size: 14px" />
        </template>

        <div class="ptt-task">
          <div class="ptt-task-header">
            <span class="ptt-task-title">{{ task.description }}</span>
            <a-tag v-if="task.important" color="orange">Importante</a-tag>
          </div>
          <span class="ptt-task-meta">{{ formatOffset(task.days_offset, task.time_of_day, task.time) }}</span>

          <div v-if="task.alerts.length" class="ptt-alerts">
            <div
              v-for="alert in task.alerts"
              :key="alert.guid"
              class="ptt-alert"
              :class="{ 'ptt-alert--critical': alert.require_confirmation }"
            >
              <div class="ptt-alert-header">
                <span class="ptt-alert-meta">
                  {{ formatOffset(alert.offset_days, alert.time_of_day, alert.time) }}
                </span>
                <a-tag v-if="alert.require_confirmation" color="red">Requiere confirmación</a-tag>
              </div>
              <p class="ptt-alert-message">{{ alert.message }}</p>

              <div class="ptt-alert-roles">
                <RoleChip v-for="role in groupAlertRoles(alert)" :key="role" :role="role" />
              </div>
            </div>
          </div>
          <span v-else class="ptt-empty">Sin alertas para esta tarea.</span>
        </div>
      </a-timeline-item>
    </a-timeline>
  </div>
</template>

<style scoped>
.ptt-timeline {
  margin-top: 4px;
}

.ptt-task {
  padding-bottom: 4px;
}

.ptt-task-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 2px;
}

.ptt-task-title {
  font-weight: 600;
  color: var(--dt-title, #fff);
}

.ptt-task-meta {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}

.ptt-alerts {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 10px;
}

.ptt-alert {
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 8px;
  padding: 10px 12px;
  background: rgba(255, 255, 255, 0.02);
}

.ptt-alert--critical {
  border-color: rgba(245, 34, 45, 0.4);
  background: rgba(245, 34, 45, 0.06);
}

.ptt-alert-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 4px;
  flex-wrap: wrap;
}

.ptt-alert-meta {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}

.ptt-alert-message {
  margin: 0 0 8px;
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
}

.ptt-alert-roles {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.ptt-empty {
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
}
</style>
