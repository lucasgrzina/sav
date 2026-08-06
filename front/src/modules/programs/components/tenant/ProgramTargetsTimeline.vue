<script setup lang="ts">
import { CalendarOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue'
import RoleChip from '@/components/atoms/display/RoleChip.vue'
import { formatDate } from '@/core/utils/date'
import type { ProgramTarget, ProgramAlertRecipient } from '../../types/program.types'

defineProps<{ targets: ProgramTarget[] }>()

interface RecipientGroup {
  role: string
  recipients: ProgramAlertRecipient[]
}

// Agrupa los destinatarios de una alerta por rol para no repetir la etiqueta de rol
// por cada nombre — preserva el orden de aparición.
function groupRecipientsByRole(recipients: ProgramAlertRecipient[]): RecipientGroup[] {
  const order: string[] = []
  const byRole = new Map<string, ProgramAlertRecipient[]>()

  for (const recipient of recipients) {
    if (!byRole.has(recipient.role)) {
      byRole.set(recipient.role, [])
      order.push(recipient.role)
    }
    byRole.get(recipient.role)!.push(recipient)
  }

  return order.map((role) => ({ role, recipients: byRole.get(role)! }))
}
</script>

<template>
  <div class="ptt-root">
    <a-collapse v-if="targets.length">
      <a-collapse-panel v-for="target in targets" :key="target.guid ?? target.target_date">
        <template #header>
          <div class="ptt-target-header">
            <span class="ptt-target-date">
              <CalendarOutlined />
              {{ formatDate(target.target_date) }}
            </span>
            <div class="ptt-target-animals">
              <a-tag v-for="a in target.animals" :key="a.guid">{{ a.rp }}</a-tag>
              <span v-if="!target.animals.length" class="ptt-empty">Sin animales</span>
            </div>
          </div>
        </template>

        <a-empty
          v-if="!target.tasks || !target.tasks.length"
          description="Sin tareas proyectadas para este grupo."
        />

        <a-timeline v-else class="ptt-timeline">
          <a-timeline-item
            v-for="task in target.tasks"
            :key="task.protocol_task_guid"
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
              <span class="ptt-task-meta">{{ formatDate(task.occurs_on) }} · {{ task.occurs_at }}</span>

              <div v-if="task.alerts.length" class="ptt-alerts">
                <div
                  v-for="alert in task.alerts"
                  :key="alert.protocol_task_alert_guid"
                  class="ptt-alert"
                  :class="{ 'ptt-alert--critical': alert.require_confirmation }"
                >
                  <div class="ptt-alert-header">
                    <span class="ptt-alert-meta">{{ formatDate(alert.occurs_on) }} · {{ alert.occurs_at }}</span>
                    <a-tag v-if="alert.require_confirmation" color="red">Requiere confirmación</a-tag>
                  </div>
                  <p class="ptt-alert-message">{{ alert.message }}</p>

                  <div
                    v-for="group in groupRecipientsByRole(alert.recipients)"
                    :key="group.role"
                    class="ptt-alert-recipients"
                  >
                    <RoleChip :role="group.role" />
                    <a-tag v-for="r in group.recipients" :key="r.guid" color="cyan">{{ r.name }}</a-tag>
                  </div>
                  <span v-if="!alert.recipients.length" class="ptt-empty">Sin destinatarios para este rol.</span>
                </div>
              </div>
              <span v-else class="ptt-empty">Sin alertas para esta tarea.</span>
            </div>
          </a-timeline-item>
        </a-timeline>
      </a-collapse-panel>
    </a-collapse>

    <span v-else class="ptt-empty">Este programa no tiene grupos.</span>
  </div>
</template>

<style scoped>
.ptt-target-header {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  width: 100%;
}

.ptt-target-date {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  color: var(--dt-title, #fff);
}

.ptt-target-animals {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}

.ptt-timeline {
  margin-top: 12px;
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

.ptt-alert-recipients {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}

.ptt-alert-recipients:last-child {
  margin-bottom: 0;
}

.ptt-empty {
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
}
</style>
