<script setup lang="ts">
import { ref, watch } from 'vue'
import type { ContactFormItem } from '@/modules/vets/types/vet.types'

const props = defineProps<{
  modelValue: ContactFormItem[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ContactFormItem[]]
}>()

const localContacts = ref<ContactFormItem[]>(props.modelValue.map((c) => ({ ...c })))

watch(
  () => props.modelValue,
  (newVal) => {
    if (JSON.stringify(newVal) !== JSON.stringify(localContacts.value)) {
      localContacts.value = newVal.map((c) => ({ ...c }))
    }
  },
  { deep: true },
)

watch(
  localContacts,
  (newVal) => {
    emit('update:modelValue', newVal.map((c) => ({ ...c })))
  },
  { deep: true },
)

function addContact(type: 'email' | 'phone' | 'whatsapp'): void {
  localContacts.value.push({
    type,
    value: '',
    label: null,
    is_primary: false,
    use_for_alerts: false,
  })
}

function removeContact(idx: number): void {
  localContacts.value.splice(idx, 1)
}

function setPrimary(idx: number, checked: boolean): void {
  if (checked) {
    const type = localContacts.value[idx].type
    localContacts.value.forEach((c, i) => {
      if (c.type === type) c.is_primary = i === idx
    })
  } else {
    localContacts.value[idx].is_primary = false
  }
}

function tagColor(type: ContactFormItem['type']): string {
  return type === 'email' ? 'blue' : 'green'
}

function tagLabel(type: ContactFormItem['type']): string {
  if (type === 'email') return 'Email'
  if (type === 'whatsapp') return 'WhatsApp'
  return 'Teléfono'
}

function valuePlaceholder(type: ContactFormItem['type']): string {
  if (type === 'email') return 'correo@ejemplo.com'
  return '+5491112345678'
}
</script>

<template>
  <div>
    <div v-if="localContacts.length > 0" class="contacts-list">
      <div
        v-for="(contact, idx) in localContacts"
        :key="idx"
        class="contact-row"
      >
        <a-tag
          :color="tagColor(contact.type)"
          class="contact-type-tag"
        >
          {{ tagLabel(contact.type) }}
        </a-tag>

        <a-input
          v-model:value="contact.value"
          :placeholder="valuePlaceholder(contact.type)"
          class="contact-value-input"
        />

        <a-input
          :value="contact.label ?? ''"
          placeholder="Etiqueta (opcional)"
          class="contact-label-input"
          @change="(e: Event) => { contact.label = (e.target as HTMLInputElement).value || null }"
        />

        <a-checkbox
          :checked="contact.is_primary"
          class="contact-primary-check"
          @update:checked="(val: boolean) => setPrimary(idx, val)"
        >
          Principal
        </a-checkbox>

        <a-button
          type="text"
          danger
          size="small"
          class="contact-remove-btn"
          @click.prevent="removeContact(idx)"
        >
          Quitar
        </a-button>
      </div>
    </div>

    <div v-else class="contacts-empty">
      Sin contactos agregados.
    </div>

    <div class="contact-add-actions">
      <a-button size="small" @click.prevent="addContact('email')">+ Agregar email</a-button>
      <a-button size="small" @click.prevent="addContact('phone')">+ Agregar teléfono</a-button>
      <a-button size="small" @click.prevent="addContact('whatsapp')">+ Agregar WhatsApp</a-button>
    </div>
  </div>
</template>

<style scoped>
.contacts-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 12px;
}

.contact-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.contact-type-tag {
  flex-shrink: 0;
  margin: 0;
  min-width: 72px;
  text-align: center;
}

.contact-value-input {
  flex: 2;
  min-width: 160px;
}

.contact-label-input {
  flex: 1;
  min-width: 120px;
}

.contact-primary-check {
  flex-shrink: 0;
  white-space: nowrap;
}

.contact-remove-btn {
  flex-shrink: 0;
}

.contacts-empty {
  font-size: 13px;
  color: var(--dt-muted, #6B8CAE);
  padding: 8px 0;
  margin-bottom: 12px;
}

.contact-add-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
</style>
