<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useVetStore } from '@/core/stores/vet.store'
import { SwapOutlined } from '@ant-design/icons-vue'
import { getRoleLabel } from '@/core/utils/roles'

const route    = useRoute()
const router   = useRouter()
const vetStore = useVetStore()

const shouldShow = computed(() => vetStore.userVets.length >= 2)

const currentGuid = computed(() => route.params.vetGuid as string)

const currentVetItem = computed(() =>
  vetStore.userVets.find(v => v.guid === currentGuid.value) ?? null
)

const otherVets = computed(() =>
  vetStore.userVets.filter(v => v.guid !== currentGuid.value)
)

function switchToVet(targetGuid: string): void {
  if (targetGuid === currentGuid.value) return

  vetStore.clearCurrentVet()

  const currentPath = route.path
  const relPath = currentPath.replace(`/vets/${currentGuid.value}`, '')
  const newPath = `/vets/${targetGuid}${relPath || ''}`

  router.push(newPath)
}
</script>

<template>
  <div v-if="shouldShow" class="vet-switcher">
    <a-dropdown trigger="click" placement="bottomRight">
      <button class="vet-switcher__trigger" :title="`Cambiar veterinaria (${currentVetItem?.name ?? '...'})`">
        <span class="vet-switcher__name">{{ currentVetItem?.name ?? '...' }}</span>
        <SwapOutlined class="vet-switcher__icon" />
      </button>
      <template #overlay>
        <a-menu>
          <a-menu-item
            v-for="vet in otherVets"
            :key="vet.guid"
            @click="switchToVet(vet.guid)"
          >
            <div class="vet-switcher__option">
              <img
                v-if="vet.logo_path"
                :src="vet.logo_path"
                :alt="vet.name"
                class="vet-switcher__option-logo"
              />
              <span v-else class="vet-switcher__option-avatar">
                {{ vet.name.slice(0, 2).toUpperCase() }}
              </span>
              <div class="vet-switcher__option-info">
                <span class="vet-switcher__option-name">{{ vet.name }}</span>
                <span class="vet-switcher__option-role">{{ getRoleLabel(vet.role.name) }}</span>
              </div>
            </div>
          </a-menu-item>
        </a-menu>
      </template>
    </a-dropdown>
  </div>
</template>

<style scoped>
.vet-switcher__trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: none;
  border: 1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: 8px;
  padding: 6px 12px;
  color: var(--dt-text, #C8E2EF);
  font-size: 13px;
  cursor: pointer;
  transition: border-color 0.15s;
}
.vet-switcher__trigger:hover {
  border-color: var(--dt-accent, #1AE5A0);
}
.vet-switcher__icon {
  font-size: 12px;
  color: var(--dt-muted, #6B8CAE);
}
.vet-switcher__option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 0;
}
.vet-switcher__option-logo {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  object-fit: cover;
}
.vet-switcher__option-avatar {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: var(--dt-accent, #1AE5A0);
  color: #000;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.vet-switcher__option-info {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.vet-switcher__option-name {
  font-size: 13px;
  color: var(--dt-text, #C8E2EF);
  font-weight: 500;
}
.vet-switcher__option-role {
  font-size: 11px;
  color: var(--dt-muted, #6B8CAE);
}
</style>
