<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import {
  DashboardOutlined,
  TeamOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  CustomerServiceOutlined,
  ControlOutlined,
  MedicineBoxOutlined,
  IdcardOutlined,
  PlayCircleOutlined,
  ExperimentOutlined,
  HeartOutlined,
} from '@ant-design/icons-vue'
import { usePermission } from '@/core/composables/usePermissions'

defineProps<{ collapsed: boolean }>()

const route = useRoute()
const { can } = usePermission()

const systemNavItems = [
  { path: '/dashboard',        label: 'Dashboard',        icon: DashboardOutlined,         permission: null                    },
  { path: '/users',            label: 'Usuarios',          icon: TeamOutlined,              permission: 'users.read'            },
  { path: '/roles',            label: 'Roles y Permisos',  icon: SafetyCertificateOutlined, permission: 'roles.read'            },
  { path: '/settings',         label: 'Configuración',     icon: SettingOutlined,           permission: null                    },
  { path: '/support-messages', label: 'Soporte',           icon: CustomerServiceOutlined,   permission: 'support-messages.read' },
  { path: '/tutorials',        label: 'Tutoriales',        icon: PlayCircleOutlined,        permission: 'tutorials.read'        },
]

const adminNavItems = [
  { path: '/admin/vets',            label: 'Veterinarias',   icon: MedicineBoxOutlined, permission: 'vets.read'              },
  { path: '/admin/clients',         label: 'Clientes',       icon: IdcardOutlined,      permission: 'clients.read'           },
  { path: '/admin/system-settings', label: 'Config. global', icon: ControlOutlined,     permission: 'system-settings.manage' },
]

const reproNavItems = [
  { path: '/techniques', label: 'Técnicas', icon: ExperimentOutlined, permission: 'techniques.read' },
]

const healthNavItems = [
  { path: '/health/activities',  label: 'Actividades',          icon: HeartOutlined, permission: 'health-activities.read'       },
  { path: '/health/categories',  label: 'Categorías de Planes', icon: HeartOutlined, permission: 'health-plan-categories.read'  },
  { path: '/health/templates',   label: 'Plantillas de Planes', icon: HeartOutlined, permission: 'health-plan-templates.read'   },
]

const navItems = computed(() =>
  systemNavItems.filter(item => !item.permission || can(item.permission))
)

const adminItems = computed(() =>
  adminNavItems.filter(item => !item.permission || can(item.permission))
)

const reproItems = computed(() =>
  reproNavItems.filter(item => !item.permission || can(item.permission))
)

const healthItems = computed(() =>
  healthNavItems.filter(item => !item.permission || can(item.permission))
)
</script>

<template>
  <nav class="dash-nav">
    <Transition name="label-fade">
      <span v-if="!collapsed" class="dash-nav-section">Sistema</span>
    </Transition>
    <RouterLink
      v-for="item in navItems"
      :key="item.path"
      :to="item.path"
      class="dash-nav-item"
      :class="{ 'is-active': route.path === item.path }"
      :title="collapsed ? item.label : undefined"
    >
      <component :is="item.icon" class="dash-nav-icon" />
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-label">{{ item.label }}</span>
      </Transition>
    </RouterLink>

    <template v-if="reproItems.length">
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-section">Reproducción</span>
      </Transition>
      <RouterLink
        v-for="item in reproItems"
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

    <template v-if="healthItems.length">
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-section">Sanidad</span>
      </Transition>
      <RouterLink
        v-for="item in healthItems"
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

    <template v-if="adminItems.length">
      <Transition name="label-fade">
        <span v-if="!collapsed" class="dash-nav-section dash-nav-section--admin">Administración</span>
      </Transition>
      <RouterLink
        v-for="item in adminItems"
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
  </nav>
</template>
