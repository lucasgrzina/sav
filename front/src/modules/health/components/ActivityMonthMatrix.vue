<script setup lang="ts">
import { shallowRef, computed } from 'vue'
import { HolderOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useTheme } from '@/core/composables/useTheme'
import { PALETTE_OPTIONS } from '@/core/themes/palettes'
import type { HealthActivity, ActivityAssignment } from '../types/health.types'

const props = defineProps<{
  availableActivities: HealthActivity[]
}>()

const assignments = defineModel<ActivityAssignment[]>({ required: true })

const MONTH_LABELS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']

// BaseDrawer teletransporta a document.body, fuera de .dash-root, así que
// var(--dt-accent) nunca resuelve acá adentro. Se lee la paleta del usuario
// directo del composable (estado JS reactivo) en vez de depender del árbol CSS.
const { palette } = useTheme()
const accentColor = computed(
  () => PALETTE_OPTIONS.find(p => p.key === palette.value)?.color ?? '#1AE5A0',
)

const draggingIndex = shallowRef<number | null>(null)
const dragOverIndex = shallowRef<number | null>(null)

const canAddMore = computed(() => {
  if (props.availableActivities.length === 0) return false
  const usedCount = assignments.value.filter(a => a.health_activity_guid).length
  return usedCount < props.availableActivities.length
})

const addButtonTooltip = computed(() => {
  if (props.availableActivities.length === 0)
    return 'Primero creá actividades en Sanidad → Actividades'
  if (!canAddMore.value)
    return 'Ya agregaste todas las actividades disponibles'
  return ''
})

function availableForRow(rowIndex: number): HealthActivity[] {
  const usedGuids = new Set(
    assignments.value
      .filter((_, i) => i !== rowIndex)
      .map(a => a.health_activity_guid)
      .filter(Boolean),
  )
  return props.availableActivities.filter(a => !usedGuids.has(a.guid))
}

function updateGuid(index: number, guid: string) {
  const current = [...assignments.value]
  current[index] = { health_activity_guid: guid, months: [] }
  assignments.value = current
}

function toggleMonth(rowIndex: number, month: number) {
  const current = [...assignments.value]
  const months = [...(current[rowIndex]?.months ?? [])]
  const idx = months.indexOf(month)
  if (idx === -1) {
    months.push(month)
    months.sort((a, b) => a - b)
  } else {
    months.splice(idx, 1)
  }
  current[rowIndex] = { ...current[rowIndex], months }
  assignments.value = current
}

function isMonthChecked(rowIndex: number, month: number): boolean {
  return assignments.value[rowIndex]?.months.includes(month) ?? false
}

function addRow() {
  assignments.value = [...assignments.value, { health_activity_guid: '', months: [] }]
}

function removeRow(index: number) {
  const current = [...assignments.value]
  current.splice(index, 1)
  assignments.value = current
}

function onDragStart(event: DragEvent, index: number) {
  draggingIndex.value = index
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
  }
}

function onDragOver(event: DragEvent, index: number) {
  event.preventDefault()
  dragOverIndex.value = index
}

function onDragLeave() {
  dragOverIndex.value = null
}

function onDrop(index: number) {
  if (draggingIndex.value === null || draggingIndex.value === index) {
    draggingIndex.value = null
    dragOverIndex.value = null
    return
  }
  const items = [...assignments.value]
  const [dragged] = items.splice(draggingIndex.value, 1)
  items.splice(index, 0, dragged)
  assignments.value = items
  draggingIndex.value = null
  dragOverIndex.value = null
}

function onDragEnd() {
  draggingIndex.value = null
  dragOverIndex.value = null
}
</script>

<template>
  <div class="amm-root" :style="{ '--amm-accent': accentColor }">
    <div class="amm-scroll-wrapper">
      <table class="amm-table">
        <thead>
          <tr>
            <th class="amm-th amm-th--drag" />
            <th class="amm-th amm-th--activity">
              Actividades <span class="amm-required">*</span>
            </th>
            <th v-for="(label, i) in MONTH_LABELS" :key="i" class="amm-th amm-th--month">
              {{ label }}
            </th>
            <th class="amm-th amm-th--delete" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(assignment, index) in assignments"
            :key="index"
            class="amm-row"
            :class="{ 'amm-row--drag-over': dragOverIndex === index && draggingIndex !== index }"
            draggable="true"
            @dragstart="onDragStart($event, index)"
            @dragover="onDragOver($event, index)"
            @dragleave="onDragLeave"
            @drop="onDrop(index)"
            @dragend="onDragEnd"
          >
            <td class="amm-td amm-td--drag">
              <HolderOutlined class="amm-drag-handle" />
            </td>

            <td class="amm-td amm-td--activity">
              <a-select
                :value="assignment.health_activity_guid || undefined"
                placeholder="Seleccioná una actividad"
                style="width: 100%"
                @update:value="(val: string) => updateGuid(index, val)"
              >
                <a-select-option
                  v-for="act in availableForRow(index)"
                  :key="act.guid"
                  :value="act.guid"
                >
                  {{ act.name }}
                </a-select-option>
              </a-select>
            </td>

            <td v-for="m in 12" :key="m" class="amm-td amm-td--month">
              <input
                type="checkbox"
                class="amm-checkbox"
                :checked="isMonthChecked(index, m)"
                :disabled="!assignment.health_activity_guid"
                @change="toggleMonth(index, m)"
              />
            </td>

            <td class="amm-td amm-td--delete">
              <BaseButton
                variant="row-action"
                size="small"
                danger
                tooltip="Eliminar actividad"
                @click="removeRow(index)"
              >
                <template #icon><DeleteOutlined /></template>
              </BaseButton>
            </td>
          </tr>

          <tr v-if="assignments.length === 0">
            <td :colspan="15" class="amm-empty">
              Hacé clic en "Añadir a actividades" para agregar actividades al plan.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="amm-footer">
      <a-tooltip :title="addButtonTooltip">
        <span>
          <a-button
            type="default"
            :disabled="!canAddMore"
            @click="addRow"
          >
            <template #icon><PlusOutlined /></template>
            Añadir a actividades
          </a-button>
        </span>
      </a-tooltip>
    </div>
  </div>
</template>

<style scoped>
.amm-root {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
}

.amm-scroll-wrapper {
  overflow-x: auto;
  width: 100%;
  border: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  border-radius: 10px;
}

.amm-table {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  font-size: 13px;
}

/* ── HEADERS ─────────────────────────────────────── */

.amm-th {
  padding: 10px 6px;
  text-align: center;
  font-weight: 600;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--dt-muted, #6B8CAE);
  border-bottom: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
  white-space: nowrap;
  background: rgba(26, 229, 160, 0.04);
}

.amm-th--drag {
  width: 32px;
}

.amm-th--activity {
  text-align: left;
  padding-left: 12px;
}

.amm-th--month {
  width: 34px;
  padding: 10px 2px;
}

.amm-th--delete {
  width: 36px;
}

.amm-required {
  color: #ff4d4f;
  margin-left: 2px;
}

/* ── ROWS ─────────────────────────────────────────── */

.amm-row {
  transition: background-color 0.1s;
}

.amm-row:not(:last-child) {
  border-bottom: 1px solid var(--dt-border, rgba(26, 229, 160, 0.12));
}

.amm-row--drag-over {
  background-color: var(--dt-hover, rgba(26, 229, 160, 0.06));
  outline: 2px solid var(--amm-accent);
  outline-offset: -2px;
}

/* ── CELLS ────────────────────────────────────────── */

.amm-td {
  padding: 8px 6px;
  vertical-align: middle;
  text-align: center;
}

.amm-td--drag {
  padding: 8px 4px 8px 8px;
  width: 32px;
}

.amm-td--activity {
  text-align: left;
  padding-left: 12px;
}

.amm-td--month {
  width: 34px;
  padding: 8px 2px;
}

.amm-td--delete {
  width: 36px;
  padding: 4px;
}

/* ── DRAG HANDLE ──────────────────────────────────── */

.amm-drag-handle {
  cursor: grab;
  color: var(--dt-muted, #6B8CAE);
  font-size: 14px;
  display: block;
  margin: 0 auto;
  padding: 4px;
}

.amm-drag-handle:active {
  cursor: grabbing;
}

/* ── CHECKBOX ─────────────────────────────────────── */

.amm-checkbox {
  cursor: pointer;
  accent-color: var(--amm-accent);
  width: 15px;
  height: 15px;
}

.amm-checkbox:disabled {
  cursor: not-allowed;
  opacity: 0.35;
}

/* ── EMPTY STATE ──────────────────────────────────── */

.amm-empty {
  text-align: center;
  padding: 24px;
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
}

/* ── FOOTER ───────────────────────────────────────── */

.amm-footer {
  display: flex;
  justify-content: center;
}
</style>
