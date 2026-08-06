<script setup lang="ts">
import { ExperimentOutlined } from '@ant-design/icons-vue'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import type { VetProtocolDetail } from '../../types/vet-protocol.types'

defineProps<{ protocol: VetProtocolDetail }>()
</script>

<template>
  <BaseCard>
    <template #header>
      <span class="pic-card-title"><ExperimentOutlined /> Datos generales</span>
    </template>

    <dl class="pic-dl">
      <dt>Nombre</dt>
      <dd>{{ protocol.name }}</dd>

      <dt>Técnica</dt>
      <dd>{{ protocol.technique.name }}</dd>

      <dt>Origen</dt>
      <dd>
        <a-tag v-if="protocol.is_global" color="blue">Global</a-tag>
        <a-tag v-else color="green">Propio</a-tag>
      </dd>

      <dt>Tareas</dt>
      <dd>{{ protocol.tasks_count }}</dd>

      <dt>Creado</dt>
      <dd>{{ new Date(protocol.created_at).toLocaleDateString('es-AR') }}</dd>
    </dl>
  </BaseCard>
</template>

<style scoped>
.pic-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: 'Syne', sans-serif;
  font-size: 14px;
  font-weight: 700;
}

.pic-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 16px;
  margin: 0;
}

.pic-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.pic-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
  word-break: break-word;
}
</style>
