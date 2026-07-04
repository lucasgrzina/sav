<script setup lang="ts">
import { formatDate } from '@/core/utils/date'
import type { VetItem } from '@/modules/vets/types/vet.types'
import { getVetStatus }  from '../api/vets.mapper'
import VetStatusBadge    from '../components/VetStatusBadge.vue'
defineProps<{ vet: VetItem }>()
</script>

<template>
  <div class="vic-grid">
    <div class="vic-card">
      <h3 class="vic-card-title">Datos fiscales</h3>
      <dl class="vic-dl">
        <dt>País</dt>
        <dd>{{ vet.country?.name ?? '—' }}</dd>

        <dt>Tipo de documento</dt>
        <dd>{{ vet.document_type?.name ?? '—' }}</dd>

        <dt>Identificador fiscal</dt>
        <dd>{{ vet.tax_id }}</dd>

        <dt>Número de matrícula</dt>
        <dd>{{ vet.registration_number ?? '—' }}</dd>
      </dl>
    </div>

    <div class="vic-card">
      <h3 class="vic-card-title">Estado y fechas</h3>
      <dl class="vic-dl">
        <dt>Estado</dt>
        <dd><VetStatusBadge :status="getVetStatus(vet)" /></dd>

        <dt>Fecha de alta</dt>
        <dd>{{ formatDate(vet.created_at) }}</dd>

        <dt>Validada el</dt>
        <dd>{{ vet.validated_at ? formatDate(vet.validated_at) : 'Sin validar' }}</dd>

        <dt>Validada por</dt>
        <dd>{{ vet.validated_by?.name ?? '—' }}</dd>

        <dt>Suspendida el</dt>
        <dd>{{ vet.suspended_at ? formatDate(vet.suspended_at) : '—' }}</dd>
      </dl>
    </div>

    <div class="vic-card">
      <h3 class="vic-card-title">Personalización de documentos</h3>
      <dl class="vic-dl">
        <dt>Título del PDF</dt>
        <dd>{{ vet.pdf_title ?? '—' }}</dd>

        <dt>Subtítulo del PDF</dt>
        <dd>{{ vet.pdf_subtitle ?? '—' }}</dd>
      </dl>
    </div>

    <div v-if="vet.contacts && vet.contacts.length > 0" class="vic-card vic-card--full">
      <h3 class="vic-card-title">Contactos</h3>
      <div class="vic-contacts">
        <div
          v-for="contact in vet.contacts"
          :key="contact.guid"
          class="vic-contact-item"
        >
          <span class="vic-contact-type">{{ contact.type }}</span>
          <span class="vic-contact-value">{{ contact.value }}</span>
          <span v-if="contact.label" class="vic-contact-label">{{ contact.label }}</span>
          <span v-if="contact.is_primary" class="vic-contact-badge">Principal</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.vic-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.vic-card {
  background: var(--dt-card, #0E2038);
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 16px;
  padding: 20px 24px;
}

.vic-card--full {
  grid-column: 1 / -1;
}

.vic-card-title {
  font-family: 'Syne', sans-serif;
  font-size: 14px;
  font-weight: 700;
  color: var(--dt-title, #fff);
  margin: 0 0 16px;
}

.vic-dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 8px 16px;
  margin: 0;
}

.vic-dl dt {
  font-size: 12px;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  white-space: nowrap;
}

.vic-dl dd {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  margin: 0;
  word-break: break-word;
}

.vic-contacts {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.vic-contact-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  flex-wrap: wrap;
}

.vic-contact-type {
  text-transform: capitalize;
  font-weight: 600;
  color: var(--dt-muted, #6B8CAE);
  font-size: 12px;
  min-width: 70px;
}

.vic-contact-label {
  color: var(--dt-muted, #6B8CAE);
  font-size: 12px;
}

.vic-contact-badge {
  font-size: 11px;
  background: rgba(26,229,160,0.15);
  color: var(--dt-accent, #1AE5A0);
  border-radius: 4px;
  padding: 1px 6px;
}
</style>
