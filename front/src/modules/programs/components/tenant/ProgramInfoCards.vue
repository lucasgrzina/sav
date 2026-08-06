<script setup lang="ts">
import { computed } from 'vue'
import { ExperimentOutlined, TeamOutlined, MessageOutlined } from '@ant-design/icons-vue'
import { getRoleLabel } from '@/core/utils/roles'
import BaseCard from '@/components/atoms/cards/BaseCard.vue'
import type { ProgramDetail } from '../../types/program.types'

const props = defineProps<{ program: ProgramDetail }>()

const vetManagers = computed(() => props.program.managers.filter((m) => m.origin === 'vet'))
const clientManagers = computed(() => props.program.managers.filter((m) => m.origin === 'client'))
</script>

<template>
  <div class="pic-stack">
    <BaseCard>
      <template #header>
        <span class="pic-card-title"><ExperimentOutlined /> Datos generales</span>
      </template>

      <dl class="pic-dl">
        <dt>Cliente</dt>
        <dd>{{ program.client.name }}</dd>

        <dt>Establecimiento</dt>
        <dd>{{ program.establishment.name }}</dd>

        <dt>Técnica</dt>
        <dd>{{ program.technique.name }}</dd>

        <dt>Protocolo</dt>
        <dd>{{ program.protocol.name }}</dd>

        <dt>Estado</dt>
        <dd>
          <a-tag v-if="program.cancelled_at" color="red">Cancelado</a-tag>
          <a-tag v-else color="green">Activo</a-tag>
        </dd>
      </dl>
    </BaseCard>

    <BaseCard>
      <template #header>
        <span class="pic-card-title"><TeamOutlined /> Responsables</span>
      </template>

      <span v-if="!program.managers.length" class="pic-empty">Sin responsables asignados.</span>

      <template v-else>
        <div class="pic-managers-group">
          <span class="pic-managers-label">Veterinaria</span>
          <div class="pic-tags">
            <a-tag v-for="m in vetManagers" :key="m.guid" color="blue">
              {{ m.name }} · {{ getRoleLabel(m.role) }}
            </a-tag>
            <span v-if="!vetManagers.length" class="pic-empty">Sin responsables de veterinaria.</span>
          </div>
        </div>

        <div class="pic-managers-group">
          <span class="pic-managers-label">Cliente</span>
          <div class="pic-tags">
            <a-tag v-for="m in clientManagers" :key="m.guid" color="purple">
              {{ m.name }} · {{ getRoleLabel(m.role) }}
            </a-tag>
            <span v-if="!clientManagers.length" class="pic-empty">Sin responsables de cliente.</span>
          </div>
        </div>
      </template>
    </BaseCard>

    <BaseCard v-if="program.comments">
      <template #header>
        <span class="pic-card-title"><MessageOutlined /> Comentarios</span>
      </template>
      <p class="pic-comments-text">{{ program.comments }}</p>
    </BaseCard>
  </div>
</template>

<style scoped>
.pic-stack {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 24px;
}

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

.pic-managers-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 12px;
}

.pic-managers-group:last-child {
  margin-bottom: 0;
}

.pic-managers-label {
  font-weight: 600;
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}

.pic-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.pic-comments-text {
  margin: 0;
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  white-space: pre-wrap;
}

.pic-empty {
  color: var(--dt-muted, #6B8CAE);
  font-size: 13px;
}
</style>
