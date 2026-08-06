<script setup lang="ts">
import { computed } from 'vue'
import BaseTag from './BaseTag.vue'
import { getRoleLabel } from '@/core/utils/roles'

const props = defineProps<{ role: string }>()

// Paleta fija por origen de tenant: azules para roles `vet`/`vet-*`, púrpuras para
// roles `client-*`. Dentro de cada escala, a mayor jerarquía (índice más bajo) el
// tono es más oscuro — refuerza visualmente quién tiene más autoridad en el perfil.
const VET_SHADES = ['#0958d9', '#1677ff', '#69b1ff']
const CLIENT_SHADES = ['#531dab', '#722ed1', '#9254de']

const ROLE_HIERARCHY: Record<string, number> = {
  vet: 0,
  'vet-administrative': 1,
  'vet-assistant': 2,
  'client-owner': 0,
  'client-manager': 1,
  'client-administrative': 2,
}

const isVetRole = computed(() => props.role === 'vet' || props.role.startsWith('vet-'))

const color = computed(() => {
  const shades = isVetRole.value ? VET_SHADES : CLIENT_SHADES
  const level = ROLE_HIERARCHY[props.role] ?? shades.length - 1
  return shades[Math.min(level, shades.length - 1)]
})

const label = computed(() => getRoleLabel(props.role))
</script>

<template>
  <BaseTag :color="color">{{ label }}</BaseTag>
</template>
