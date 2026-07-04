<script setup lang="ts">
import { ref } from 'vue'
import { useSystemSettings } from '../composables/useSystemSettings'
import { useUpdateSystemSetting } from '../composables/useUpdateSystemSetting'
import AppHeader from '@/components/layouts/partials/AppHeader.vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

const { data: settings, isLoading } = useSystemSettings()
const { mutate: updateSetting, fieldErrors, generalError } = useUpdateSystemSetting()

const editingCode = ref<string | null>(null)
const editingValue = ref<string>('')

function openEdit(code: string, currentValue: string | number | boolean) {
    editingCode.value = code
    editingValue.value = String(currentValue)
}

function closeEdit() {
    editingCode.value = null
    editingValue.value = ''
}

function confirmEdit() {
    if (!editingCode.value) return
    updateSetting(
        { code: editingCode.value, payload: { value: editingValue.value } },
        { onSuccess: () => closeEdit() },
    )
}
</script>

<template>
    <div>
        <AppHeader title="Configuración del Sistema" subtitle="Parámetros globales de la plataforma" />

        <div class="p-6">
            <a-spin :spinning="isLoading">
                <a-table
                    :data-source="settings ?? []"
                    row-key="code"
                    :pagination="false"
                >
                    <a-table-column title="Código" data-index="code" />
                    <a-table-column title="Descripción" data-index="description" />
                    <a-table-column title="Valor" data-index="value" />
                    <a-table-column title="Tipo" data-index="type" />
                    <a-table-column title="Actualizado" data-index="updated_at" />
                    <a-table-column title="Acciones">
                        <template #default="{ record }">
                            <BaseButton variant="secondary" size="small" @click="openEdit(record.code, record.value)">
                                Editar
                            </BaseButton>
                        </template>
                    </a-table-column>
                </a-table>
            </a-spin>
        </div>

        <a-modal
            :open="editingCode !== null"
            title="Editar configuración"
            :footer="null"
            @cancel="closeEdit"
        >
            <div class="mb-4">
                <a-alert v-if="generalError" :message="generalError" type="error" show-icon class="mb-3" />
                <a-form layout="vertical">
                    <a-form-item
                        label="Valor"
                        :validate-status="fieldErrors?.value ? 'error' : ''"
                        :help="fieldErrors?.value"
                    >
                        <a-input v-model:value="editingValue" />
                    </a-form-item>
                </a-form>
                <div class="flex justify-end gap-2 mt-4">
                    <BaseButton variant="secondary" @click="closeEdit">Cancelar</BaseButton>
                    <BaseButton variant="primary" @click="confirmEdit">Guardar</BaseButton>
                </div>
            </div>
        </a-modal>
    </div>
</template>
