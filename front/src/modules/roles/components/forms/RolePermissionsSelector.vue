<script setup lang="ts">
import { computed } from 'vue'
import type { PermissionGroup } from '../../types/role.types'

const props = defineProps<{
  modelValue: string[]
  permissionGroups: PermissionGroup[]
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[]]
}>()

const selected = computed({
  get:  () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const totalCount    = computed(() => props.permissionGroups.flatMap(g => g.permissions).length)
const selectedCount = computed(() => props.modelValue.length)

function groupSelected(group: PermissionGroup): number {
  return group.permissions.filter(p => selected.value.includes(p.guid)).length
}

function isAll(group: PermissionGroup): boolean {
  return group.permissions.length > 0 && group.permissions.every(p => selected.value.includes(p.guid))
}

function toggleGroup(group: PermissionGroup) {
  const guids = group.permissions.map(p => p.guid)
  if (isAll(group)) {
    selected.value = selected.value.filter(g => !guids.includes(g))
  } else {
    selected.value = [...new Set([...selected.value, ...guids])]
  }
}

function togglePerm(guid: string) {
  if (selected.value.includes(guid)) {
    selected.value = selected.value.filter(g => g !== guid)
  } else {
    selected.value = [...selected.value, guid]
  }
}

function selectAll() {
  selected.value = props.permissionGroups.flatMap(g => g.permissions.map(p => p.guid))
}

function clearAll() {
  selected.value = []
}

function labelFor(name: string): string {
  const parts = name.split('.')
  return parts.length === 1
    ? name.charAt(0).toUpperCase() + name.slice(1)
    : parts.slice(1).map(p => p.charAt(0).toUpperCase() + p.slice(1)).join(' ')
}

function initials(module: string): string {
  return module.slice(0, 2).toUpperCase()
}

const HUE: Record<string, string> = {
  users:       '155',
  roles:       '210',
  permissions: '38',
  exports:     '270',
}
function hueFor(module: string): string {
  return HUE[module.toLowerCase()] ?? '155'
}
</script>

<template>
  <!-- Loading -->
  <div v-if="loading" class="ps-skeleton-list">
    <div v-for="i in 3" :key="i" class="ps-skeleton-block">
      <div class="ps-skeleton-header" />
      <div v-for="j in 3" :key="j" class="ps-skeleton-row" />
    </div>
  </div>

  <div v-else class="ps-root">

    <!-- Summary bar -->
    <div class="ps-summary">
      <span class="ps-summary-text">
        <span class="ps-summary-count">{{ selectedCount }}</span>
        <span class="ps-summary-of"> de {{ totalCount }} permisos seleccionados</span>
      </span>
      <div class="ps-summary-actions">
        <button type="button" class="ps-action-btn" :disabled="selectedCount === 0" @click="clearAll">
          Limpiar
        </button>
        <button type="button" class="ps-action-btn accent" :disabled="selectedCount === totalCount" @click="selectAll">
          Seleccionar todos
        </button>
      </div>
    </div>

    <!-- Permission groups -->
    <div class="ps-groups">
      <div
        v-for="group in permissionGroups"
        :key="group.module"
        class="ps-group"
        :style="{ '--hue': hueFor(group.module) }"
      >
        <!-- Group header -->
        <div class="ps-group-head">
          <div class="ps-mod-info">
            <!--span class="ps-badge">{{ initials(group.module) }}</span-->
            <span class="ps-mod-name">{{ group.module }}</span>
            <span class="ps-count-pill">
              {{ groupSelected(group) }}<span class="ps-count-sep">/</span>{{ group.permissions.length }}
            </span>
          </div>
          <a-switch
            :checked="isAll(group)"
            size="small"
            @change="toggleGroup(group)"
          />
        </div>

        <!-- Permission rows -->
        <a-list
          class="ps-perm-list"
          :data-source="group.permissions"
          :split="false"
        >
          <template #renderItem="{ item: perm }">
            <a-list-item
              class="ps-perm-row"
              @click="togglePerm(perm.guid)"
            >
              <span class="ps-perm-label">{{ labelFor(perm.name) }}</span>
              <template #actions>
                <span @click.stop>
                  <a-switch
                    :checked="selected.includes(perm.guid)"
                    size="small"
                    @change="() => togglePerm(perm.guid)"
                  />
                </span>
              </template>
            </a-list-item>
          </template>
        </a-list>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Root ───────────────────────────────────────────────── */
.ps-root {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* ── Summary bar ────────────────────────────────────────── */
.ps-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: rgba(26, 229, 160, 0.04);
  border: 1px solid rgba(26, 229, 160, 0.1);
  border-radius: 10px;
}

.ps-summary-count {
  font-size: 15px;
  font-weight: 700;
  color: var(--dt-accent, #1AE5A0);
  font-variant-numeric: tabular-nums;
}

.ps-summary-of {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  margin-left: 2px;
}

.ps-summary-actions {
  display: flex;
  gap: 6px;
}

.ps-action-btn {
  font-size: 11.5px;
  font-weight: 600;
  padding: 4px 11px;
  border-radius: 7px;
  border: 1px solid rgba(107, 140, 174, 0.2);
  background: transparent;
  color: var(--dt-muted, #6B8CAE);
  cursor: pointer;
  transition: all 0.15s;
  font-family: 'Figtree', system-ui, sans-serif;
}

.ps-action-btn:hover:not(:disabled) {
  border-color: rgba(107, 140, 174, 0.4);
  color: var(--dt-text, #C8E2EF);
}

.ps-action-btn.accent {
  border-color: rgba(26, 229, 160, 0.2);
  color: var(--dt-accent, #1AE5A0);
}

.ps-action-btn.accent:hover:not(:disabled) {
  background: rgba(26, 229, 160, 0.08);
  border-color: rgba(26, 229, 160, 0.4);
}

.ps-action-btn:disabled {
  opacity: 0.35;
  cursor: default;
}

/* ── Groups ─────────────────────────────────────────────── */
.ps-groups {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ps-group {
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: 10px;
  overflow: hidden;
}

/* ── Group header ───────────────────────────────────────── */
.ps-group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 10px;
  background: rgba(255, 255, 255, 0.03);
  border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  cursor: default;
}

.ps-mod-info {
  display: flex;
  align-items: center;
  gap: 9px;
}

.ps-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 6px;
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.04em;
  background: hsl(var(--hue, 155), 60%, 18%);
  color: hsl(var(--hue, 155), 70%, 70%);
  border: 1px solid hsl(var(--hue, 155), 50%, 25%);
  font-family: 'Syne', sans-serif;
  flex-shrink: 0;
}

.ps-mod-name {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--dt-title, #000);
  font-family: 'Syne', sans-serif;
}

.ps-count-pill {
  font-size: 11px;
  font-weight: 700;
  color: var(--dt-accent, #1AE5A0);
  background: rgba(26, 229, 160, 0.08);
  border: 1px solid rgba(26, 229, 160, 0.18);
  border-radius: 20px;
  padding: 1px 7px;
  font-variant-numeric: tabular-nums;
  min-width: 32px;
  text-align: center;
  letter-spacing: 0;
}

.ps-count-sep {
  opacity: 0.45;
  margin: 0 1px;
}

/* ── Permission rows ────────────────────────────────────── */
.ps-perm-list {
  background: rgba(255, 255, 255, 0.015);
}

.ps-perm-list :deep(.ant-list-items) {
  margin: 0;
  padding: 0;
}

.ps-perm-row {
  cursor: pointer;
  transition: background 0.15s;
  border-bottom: 1px solid rgba(255, 255, 255, 0.04) !important;
}

.ps-perm-row:last-child {
  border-bottom: none !important;
}

.ps-perm-row:hover {
  background: rgba(26, 229, 160, 0.04);
}

.ps-perm-list :deep(.ant-list-item) {
  padding: 10px !important;
  border-block-end: none !important;
}

.ps-perm-list :deep(.ant-list-item-action) {
  margin-inline-start: 12px;
}

.ps-perm-label {
  font-size: 13.5px;
  font-weight: 500;
  /*
  font-family: 'Figtree', system-ui, sans-serif;*/
}

/* ── Loading skeleton ───────────────────────────────────── */
.ps-skeleton-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ps-skeleton-block {
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(26, 229, 160, 0.06);
}

.ps-skeleton-header {
  height: 46px;
  background: linear-gradient(
    90deg,
    rgba(20, 44, 74, 0.8) 0%,
    rgba(28, 58, 90, 0.9) 50%,
    rgba(20, 44, 74, 0.8) 100%
  );
  background-size: 200% 100%;
  animation: ps-shimmer 1.6s ease-in-out infinite;
}

.ps-skeleton-row {
  height: 41px;
  border-top: 1px solid rgba(26, 229, 160, 0.04);
  background: linear-gradient(
    90deg,
    rgba(14, 32, 56, 0.6) 0%,
    rgba(20, 44, 74, 0.8) 50%,
    rgba(14, 32, 56, 0.6) 100%
  );
  background-size: 200% 100%;
  animation: ps-shimmer 1.6s ease-in-out infinite;
}

@keyframes ps-shimmer {
  0%   { background-position:  200% 0; }
  100% { background-position: -200% 0; }
}
</style>
