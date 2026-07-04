<script setup lang="ts">
import { computed } from 'vue';
import type { PermissionGroup } from '@/modules/roles/types/role.types';

const props = defineProps<{
    groups: PermissionGroup[];
    modelValue: string[];
    loading?: boolean;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', v: string[]): void }>();

const selected = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

function isGroupChecked(group: PermissionGroup): boolean {
    return group.permissions.every(p => selected.value.includes(p.guid));
}

function isGroupIndeterminate(group: PermissionGroup): boolean {
    const some = group.permissions.some(p => selected.value.includes(p.guid));
    return some && !isGroupChecked(group);
}

function toggleGroup(group: PermissionGroup, checked: boolean) {
    const guids = group.permissions.map(p => p.guid);
    if (checked) {
        selected.value = [...new Set([...selected.value, ...guids])];
    } else {
        selected.value = selected.value.filter(g => !guids.includes(g));
    }
}

function togglePermission(guid: string, checked: boolean) {
    if (checked) {
        selected.value = [...selected.value, guid];
    } else {
        selected.value = selected.value.filter(g => g !== guid);
    }
}

function labelFor(name: string): string {
    const parts = name.split('.');
    return parts[1] ? `${parts[1].charAt(0).toUpperCase()}${parts[1].slice(1)}` : name;
}
</script>

<template>
    <div v-if="loading" class="cg-loading">Cargando permisos...</div>

    <div v-else class="cg-list">
        <div v-for="group in groups" :key="group.module" class="cg-group">
            <label class="cg-group-header">
                <input
                    type="checkbox"
                    class="cg-checkbox"
                    :checked="isGroupChecked(group)"
                    :indeterminate="isGroupIndeterminate(group)"
                    @change="toggleGroup(group, ($event.target as HTMLInputElement).checked)"
                />
                <span class="cg-module-name">{{ group.module }}</span>
            </label>
            <div class="cg-permissions">
                <label
                    v-for="perm in group.permissions"
                    :key="perm.guid"
                    class="cg-perm"
                >
                    <input
                        type="checkbox"
                        class="cg-checkbox"
                        :checked="selected.includes(perm.guid)"
                        @change="togglePermission(perm.guid, ($event.target as HTMLInputElement).checked)"
                    />
                    <span class="cg-perm-label">{{ labelFor(perm.name) }}</span>
                </label>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cg-loading {
    font-size: 13px;
    color: var(--dt-muted, #6B8CAE);
    padding: 12px 0;
}

.cg-list { display: flex; flex-direction: column; gap: 20px; }

.cg-group-header {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    margin-bottom: 10px;
}

.cg-module-name {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dt-accent, #1AE5A0);
}

.cg-permissions {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 8px;
    padding-left: 24px;
}

.cg-perm {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.cg-perm-label {
    font-size: 13.5px;
    color: var(--dt-text, #C8E2EF);
}

.cg-checkbox {
    width: 16px;
    height: 16px;
    accent-color: var(--dt-accent, #1AE5A0);
    cursor: pointer;
    flex-shrink: 0;
}
</style>
