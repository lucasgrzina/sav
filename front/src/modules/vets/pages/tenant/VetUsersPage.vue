<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { PlusOutlined } from '@ant-design/icons-vue'
import VetStaffTable from '@/modules/vets/components/VetStaffTable.vue'
import { useVetStaff }           from '@/modules/vets/composables/useVetStaff'
import { useUnlinkVetStaff }     from '@/modules/vets/composables/useUnlinkVetStaff'
import { useToggleVetStaffBlock } from '@/modules/vets/composables/useToggleVetStaffBlock'
import { useVetStore }            from '@/core/stores/vet.store'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'

const router   = useRouter()
const route    = useRoute()
const vetStore = useVetStore()

const vetGuid = computed(() => route.params.vetGuid as string)

const { data: staff, isLoading }  = useVetStaff(vetGuid)
const { unlinkStaff }             = useUnlinkVetStaff(vetGuid.value)
const { toggleBlock }             = useToggleVetStaffBlock(vetGuid.value)

function navigateToEdit(member: { guid: string }) {
  router.push(`/vets/${vetGuid.value}/usuarios/${member.guid}/editar`)
}
</script>

<template>
  <div>
    <AppHeader title="Usuarios" :subtitle="vetStore.currentVet?.name ?? 'Veterinaria'">
      <template #actions="{ buttonSize }">
        <BaseButton
          variant="primary"
          :size="buttonSize"
          @click="router.push(`/vets/${vetGuid}/usuarios/crear`)"
        >
          <template #icon><PlusOutlined /></template>
          Nuevo usuario
        </BaseButton>
      </template>
    </AppHeader>

    <VetStaffTable
      :staff="staff ?? []"
      :loading="isLoading"
      @edit="navigateToEdit"
      @toggle-block="toggleBlock"
      @unlink="unlinkStaff"
    />
  </div>
</template>
