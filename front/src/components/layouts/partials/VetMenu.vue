<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import {
  IdcardOutlined,
  TeamOutlined,
  UserOutlined,
  ArrowLeftOutlined,
  PlayCircleOutlined,
  ProfileOutlined,
  CustomerServiceOutlined,
  HeartOutlined,
  ScheduleOutlined,
} from '@ant-design/icons-vue'
import { usePermission } from '@/core/composables/usePermissions'

const props = defineProps<{ collapsed: boolean; tenantContextLoading?: boolean }>()

const route = useRoute()
const { can, hasTenantContext } = usePermission()

const vetGuid = computed(() => route.params.vetGuid as string)

const vetNavItems = computed(() => [
  { path: `/vets/${vetGuid.value}/perfil`,   label: 'Perfil',    icon: IdcardOutlined },
  { path: `/vets/${vetGuid.value}/clients`,  label: 'Clientes',  icon: TeamOutlined },
  { path: `/vets/${vetGuid.value}/usuarios`, label: 'Usuarios',  icon: UserOutlined },
])

const reproduccionNavItems = computed(() =>
  [
    { path: `/vets/${vetGuid.value}/protocols`, label: 'Protocolos', icon: HeartOutlined, permission: 'protocols.read' },
    { path: `/vets/${vetGuid.value}/programs`, label: 'Programas', icon: ScheduleOutlined, permission: 'programs.read' },
  ].filter((item) => can(item.permission)),
)

const soporteNavItems = computed(() => [
  { path: `/vets/${vetGuid.value}/soporte`,    label: 'Mensajes',   icon: CustomerServiceOutlined },
  { path: `/vets/${vetGuid.value}/tutoriales`, label: 'Tutoriales', icon: PlayCircleOutlined },
])

const visibleItems = computed(() =>
  !props.tenantContextLoading && hasTenantContext.value ? vetNavItems.value : [],
)

const visibleReproduccionItems = computed(() =>
  props.tenantContextLoading ? [] : reproduccionNavItems.value,
)
</script>

<template>
  <nav class="dash-nav">
    <Transition name="label-fade">
      <span v-if="!collapsed" class="dash-nav-section">Veterinaria</span>
    </Transition>

    <RouterLink
      v-for="item in visibleItems"
      :key="item.path"
      :to="item.path"
      class="dash-nav-item"
      :class="{ 'is-active': route.path.startsWith(item.path) }"
      :title="collapsed ? item.label : undefined"
    >
      <component :is="item.icon" class="dash-nav-icon" />
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-label">{{ item.label }}</span>
      </Transition>
    </RouterLink>

    <template v-if="visibleReproduccionItems.length">
      <div class="dash-nav-divider" />
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-section">Reproducción</span>
      </Transition>

      <RouterLink
        v-for="item in visibleReproduccionItems"
        :key="item.path"
        :to="item.path"
        class="dash-nav-item"
        :class="{ 'is-active': route.path.startsWith(item.path) }"
        :title="collapsed ? item.label : undefined"
      >
        <component :is="item.icon" class="dash-nav-icon" />
        <Transition name="label-fade">
          <span v-if="!collapsed" class="dash-nav-label">{{ item.label }}</span>
        </Transition>
      </RouterLink>
    </template>

    <div class="dash-nav-divider" />
    <Transition name="label-fade">
      <span v-if="!collapsed" class="dash-nav-section">Soporte</span>
    </Transition>

    <RouterLink
      v-for="item in soporteNavItems"
      :key="item.path"
      :to="item.path"
      class="dash-nav-item"
      :class="{ 'is-active': route.path.startsWith(item.path) }"
      :title="collapsed ? item.label : undefined"
    >
      <component :is="item.icon" class="dash-nav-icon" />
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-label">{{ item.label }}</span>
      </Transition>
    </RouterLink>

    <div class="dash-nav-divider" />
    <Transition name="label-fade">
      <span v-if="!collapsed" class="dash-nav-section">Mi perfil</span>
    </Transition>
    <RouterLink
      :to="`/vets/${vetGuid}/mi-perfil`"
      class="dash-nav-item"
      :class="{ 'is-active': route.path.startsWith(`/vets/${vetGuid}/mi-perfil`) }"
      :title="collapsed ? 'Mi perfil' : undefined"
    >
      <ProfileOutlined class="dash-nav-icon" />
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-label">Mi perfil</span>
      </Transition>
    </RouterLink>

    <template v-if="can('vets.read')">
      <div class="dash-nav-divider" />
      <RouterLink
        to="/admin/vets"
        class="dash-nav-item dash-nav-item--back"
        :title="collapsed ? 'Volver al admin' : undefined"
      >
        <ArrowLeftOutlined class="dash-nav-icon" />
        <Transition name="label-fade">
          <span v-if="!collapsed" class="dash-nav-label">Volver al admin</span>
        </Transition>
      </RouterLink>
    </template>
  </nav>
</template>
