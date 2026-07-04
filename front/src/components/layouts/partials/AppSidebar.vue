<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { MenuFoldOutlined, MenuUnfoldOutlined } from '@ant-design/icons-vue'
import AppMenu from './AppMenu.vue'
import VetMenu from './VetMenu.vue'

defineProps<{ collapsed: boolean }>()
const emit = defineEmits<{ 'update:collapsed': [value: boolean] }>()

const route = useRoute()
const isVetContext = computed(() => route.path.startsWith('/vets/'))
</script>

<template>
  <aside class="dash-sidebar" :class="{ 'is-collapsed': collapsed }">
    <div class="dash-logo">
      <div class="dash-logo-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
          <path style="fill: var(--dt-accent)" d="M12 2C9.5 2 7.5 3.8 7.5 6s2 4 4.5 4 4.5-1.8 4.5-4S14.5 2 12 2Z" opacity="0.9" />
          <circle style="fill: var(--dt-accent)" cx="6.5"  cy="6.5" r="2"   opacity="0.6"/>
          <circle style="fill: var(--dt-accent)" cx="17.5" cy="6.5" r="2"   opacity="0.6"/>
          <circle style="fill: var(--dt-accent)" cx="4"    cy="11"  r="1.8" opacity="0.5"/>
          <circle style="fill: var(--dt-accent)" cx="20"   cy="11"  r="1.8" opacity="0.5"/>
          <path style="fill: var(--dt-accent)" d="M12 13c-4.5 0-8 2.5-8 6 0 2 3.5 3 8 3s8-1 8-3c0-3.5-3.5-6-8-6Z" opacity="0.8" />
        </svg>
      </div>
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-logo-name">Vet<span>Alert</span></span>
      </Transition>
    </div>

    <VetMenu v-if="isVetContext" :collapsed="collapsed" />
    <AppMenu v-else :collapsed="collapsed" />

    <button
      class="dash-collapse-btn"
      :title="collapsed ? 'Expandir' : 'Colapsar'"
      @click="emit('update:collapsed', !collapsed)"
    >
      <MenuFoldOutlined v-if="!collapsed" />
      <MenuUnfoldOutlined v-else />
    </button>
  </aside>
</template>
