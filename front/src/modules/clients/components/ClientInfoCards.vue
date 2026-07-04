<script setup lang="ts">
import { formatDate } from '@/core/utils/date'
import type { ClientItem } from '../types/client.types'

defineProps<{ client: ClientItem }>()
</script>

<template>
  <div class="cic-grid">
    <div class="cic-card">
      <h3 class="cic-card-title">Datos fiscales</h3>
      <dl class="cic-dl">
        <dt>País</dt>
        <dd>{{ client.country?.name ?? '—' }}</dd>

        <dt>Tipo de documento</dt>
        <dd>{{ client.document_type?.name ?? '—' }}</dd>

        <dt>CUIT / Identificador</dt>
        <dd>{{ client.tax_id }}</dd>

        <dt>Fecha de alta</dt>
        <dd>{{ formatDate(client.created_at) }}</dd>
      </dl>
    </div>

    <div class="cic-card">
      <h3 class="cic-card-title">Dirección</h3>
      <dl class="cic-dl">
        <dt>Dirección</dt>
        <dd>{{ client.address ?? '—' }}</dd>

        <dt>Ciudad</dt>
        <dd>{{ client.city ?? '—' }}</dd>

        <dt>Provincia</dt>
        <dd>{{ client.state ?? '—' }}</dd>

        <dt>Código postal</dt>
        <dd>{{ client.zip_code ?? '—' }}</dd>
      </dl>
    </div>
  </div>
</template>

<style scoped>
.cic-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.cic-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 16px;
  padding: 20px 24px;
}

.cic-card-title {
  font-family: 'Syne', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0 0 16px;
}

.cic-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 16px;
  margin: 0;
}

.cic-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.cic-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
  word-break: break-word;
}
</style>
