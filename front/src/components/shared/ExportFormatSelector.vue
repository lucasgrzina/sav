<script setup>
import { useExportFormat } from '@/core/composables/useExportFormat'

const { visible, loading, format, options, confirm, cancel } = useExportFormat()
</script>

<template>
    <a-modal
        :open="visible"
        :confirm-loading="loading"
        @cancel="cancel"
        wrap-class-name="environment-create-modal"
        :footer="null"
    >
        <template #title>
            <div class="modal-header">
                <h4 class="modal-custom-title">
                    {{ $t('exportFormatSelector.title') }}
                </h4>
            </div>
        </template>

        <div class="layout-collaborator-delete-modal">
            <a-form layout="vertical">
                <a-form-item :label="$t('exportFormatSelector.formatLabel')">
                    <a-select
                        v-model:value="format"
                        placeholder="Seleccioná un formato"
                    >
                        <a-select-option
                            v-for="opt in options"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </a-select-option>
                    </a-select>
                </a-form-item>
            </a-form>

            <div class="modal-footer">
                <a-button
                    size="large"
                    type="secondary"
                    @click="cancel"
                >
                    {{ $t('buttons.cancel') }}
                </a-button>

                <a-button
                    size="large"
                    type="primary"
                    :loading="loading"
                    :disabled="loading || !format"
                    @click="confirm"
                >
                    {{ $t('buttons.confirm') }}
                </a-button>
            </div>
        </div>
    </a-modal>
</template>

<style scoped>
</style>
