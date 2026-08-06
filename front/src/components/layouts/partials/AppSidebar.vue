<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { MenuFoldOutlined, MenuUnfoldOutlined } from '@ant-design/icons-vue'
import AppMenu from './AppMenu.vue'
import VetMenu from './VetMenu.vue'

const props = withDefaults(
  defineProps<{ collapsed: boolean; tenantContextLoading?: boolean }>(),
  { tenantContextLoading: false },
)
const emit = defineEmits<{ 'update:collapsed': [value: boolean] }>()

const route = useRoute()
const isVetContext = computed(() => route.path.startsWith('/vets/'))
</script>

<template>
  <aside class="dash-sidebar" :class="{ 'is-collapsed': collapsed }">
    <div class="dash-logo">
      
      <Transition name="label-fade">
        <img src="@/assets/logo.png" alt="VetAlert" style="max-width: 100%;width: 100px;" />
        <!--img v-if="collapsed" src="@/assets/logo.png" alt="VetAlert" width="20" height="20" />
        <span v-if="!collapsed" class="dash-logo-name">Vet<span>Alert</span></span-->
      </Transition>
    </div>

    <VetMenu v-if="isVetContext" :collapsed="collapsed" :tenant-context-loading="props.tenantContextLoading" />
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
