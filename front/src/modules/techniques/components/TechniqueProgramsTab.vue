<script setup lang="ts">
import type { ProgramsStub } from '../types/technique.types'

defineProps<{
  programs: ProgramsStub
}>()
</script>

<template>
  <div>
    <a-empty
      v-if="programs.total === 0"
      description="No hay programas vinculados a esta técnica todavía."
    />
    <!--
      TODO: Cuando exista el módulo de Programas, reemplazar por BaseDataTable con columnas:
      cliente, establecimiento, protocolo, fecha objetivo, estado (badge).
      El endpoint GET /v1/techniques/{guid} retorna programs con paginación.
    -->
    <template v-else>
      <p class="tpt-placeholder">
        Programas: {{ programs.total }} total.
        (Tabla a implementar cuando exista el módulo de Programas)
      </p>
    </template>
  </div>
</template>

<style scoped>
.tpt-placeholder {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 20px 0;
}
</style>
