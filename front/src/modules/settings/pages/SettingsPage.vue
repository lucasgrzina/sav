<script setup lang="ts">
import { EditOutlined } from '@ant-design/icons-vue'
import AppHeader from '@/components/layouts/partials/AppHeader.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import SettingEditModal from '../components/SettingEditModal.vue'
import { REGISTRY_GROUPS } from '../config/settings.registry'
import { useSettingEditor } from '../composables/useSettingEditor'
import { useSettingsStore } from '../stores/settings.store'
import type { SettingDefinition } from '../config/settings.registry'
import type { SettingValue } from '../types/settings.types'

const store = useSettingsStore()
const { isOpen, isSaving, active, draftValue, open, close, save } = useSettingEditor()

function currentValue(def: SettingDefinition): SettingValue {
    return store.get(def.code, def.options[0]?.value as SettingValue) as SettingValue
}

function paletteColor(def: SettingDefinition): string | undefined {
    if (def.code !== 'theme_palette') return undefined
    const val = currentValue(def)
    return (def.options.find((o) => o.value === val) as { color?: string } | undefined)?.color
}
</script>

<template>
  <div>
    <AppHeader title="Configuración" subtitle="Preferencias personales de tu cuenta" />

    <div class="sp-groups">
      <section
        v-for="(defs, group) in REGISTRY_GROUPS"
        :key="group"
        class="sp-group"
      >
        <h2 class="sp-group-title">{{ group }}</h2>

        <div class="sp-list">
          <div
            v-for="def in defs"
            :key="def.code"
            class="sp-row"
          >
            <div class="sp-row-info">
              <span class="sp-row-label">{{ def.label }}</span>
              <span class="sp-row-value">
                <span
                  v-if="paletteColor(def)"
                  class="sp-swatch"
                  :style="{ background: paletteColor(def) }"
                />
                {{ def.format(currentValue(def)) }}
              </span>
            </div>
            <BaseButton variant="secondary" size="small" @click="open(def)">
              <template #icon><EditOutlined /></template>
              Editar
            </BaseButton>
          </div>
        </div>
      </section>
    </div>

    <SettingEditModal
      v-model:is-open="isOpen"
      v-model:draft-value="draftValue"
      :active="active"
      :is-saving="isSaving"
      @close="close"
      @save="save"
    />
  </div>
</template>

<style scoped>
.sp-groups {
    display:        flex;
    flex-direction: column;
    gap:            24px;
}

.sp-group {
    background:    var(--dt-card, #0E2038);
    border:        1px solid var(--dt-border, rgba(26,229,160,0.12));
    border-radius: var(--dt-radius-xl, 16px);
    overflow:      hidden;
}

.sp-group-title {
    margin:          0;
    padding:         16px 20px;
    font-family:     'Syne', sans-serif;
    font-size:       11px;
    font-weight:     700;
    letter-spacing:  0.1em;
    text-transform:  uppercase;
    color:           var(--dt-muted, #6B8CAE);
    border-bottom:   1px solid var(--dt-border, rgba(26,229,160,0.12));
}

.sp-list {
    display: flex;
    flex-direction: column;
}

.sp-row {
    display:         flex;
    align-items:     center;
    justify-content: space-between;
    gap:             12px;
    padding:         14px 20px;
    border-bottom:   1px solid var(--dt-border, rgba(26,229,160,0.06));
    transition:      background 0.12s;
}
.sp-row:last-child { border-bottom: none; }
.sp-row:hover      { background: var(--dt-hover, rgba(26,229,160,0.03)); }

.sp-row-info {
    display:        flex;
    flex-direction: column;
    gap:            3px;
}

.sp-row-label {
    font-size:   13.5px;
    font-weight: 500;
    color:       var(--dt-text, #C8E2EF);
}

.sp-row-value {
    display:     flex;
    align-items: center;
    gap:         6px;
    font-size:   12.5px;
    color:       var(--dt-muted, #6B8CAE);
}

.sp-swatch {
    display:       inline-block;
    width:         8px;
    height:        8px;
    border-radius: 50%;
    flex-shrink:   0;
}
</style>
