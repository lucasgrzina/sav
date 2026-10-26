<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { PREVIEW_COPY } from '../../../constants/alert-copy'
import type { PreviewItem, PreviewRecipient } from '../../../types/program-alerts.types'

const PAGE_SIZE = 50

const props = defineProps<{ items: PreviewItem[] }>()
const emit = defineEmits<{ viewMessage: [message: string] }>()

interface Group {
  key: string
  date: string
  name: string | null
  items: PreviewItem[]
}

// Grouped by target (date); each group is collapsed by default and its content is rendered
// only once opened, in pages of PAGE_SIZE, so large previews stay light.
const groups = computed<Group[]>(() => {
  const map = new Map<string, Group>()
  for (const item of props.items) {
    const group = map.get(item.target_key) ?? { key: item.target_key, date: item.target_date, name: item.target_name ?? null, items: [] }
    group.items.push(item)
    map.set(item.target_key, group)
  }
  return [...map.values()]
})

const activeKeys = ref<string[]>([])
const visible = reactive<Record<string, number>>({})

function visibleItems(group: Group): PreviewItem[] {
  return group.items.slice(0, visible[group.key] ?? PAGE_SIZE)
}

function showMore(group: Group) {
  visible[group.key] = (visible[group.key] ?? PAGE_SIZE) + PAGE_SIZE
}

function formatIsoDate(iso: string): string {
  const [y, m, d] = iso.slice(0, 10).split('-')
  return `${d}/${m}/${y}`
}

function statusOf(r: PreviewRecipient): { label: string; color: string } {
  if (!r.will_send) {
    const reason = r.skip_reason ? PREVIEW_COPY.recipientSkip[r.skip_reason] : ''
    return { label: PREVIEW_COPY.wontSend(reason), color: 'red' }
  }
  if (r.channel === 'email') return { label: PREVIEW_COPY.willSendEmail, color: 'blue' }
  return { label: PREVIEW_COPY.willSend, color: 'green' }
}

function sentence(item: PreviewItem, r: PreviewRecipient): string {
  return PREVIEW_COPY.recipientSentence(
    r.name ?? '',
    r.role,
    PREVIEW_COPY.channelLabel[r.channel] ?? r.channel,
    r.destination_masked,
    item.scheduled_at_local.label,
  )
}
</script>

<template>
  <a-collapse v-model:active-key="activeKeys" data-testid="preview-groups">
    <a-collapse-panel
      v-for="group in groups"
      :key="group.key"
      :header="PREVIEW_COPY.groupTitle(formatIsoDate(group.date), group.items.length, group.name)"
    >
      <div v-for="(item, i) in visibleItems(group)" :key="`${group.key}-${i}`" class="ppg-item">
        <div class="ppg-item-head">
          <strong>{{ item.task_description ?? '' }}</strong>
          <a-tag v-if="!item.will_send && item.skip_reason" color="red">
            {{ PREVIEW_COPY.wontSend(PREVIEW_COPY.itemSkip[item.skip_reason]) }}
          </a-tag>
        </div>
        <ul class="ppg-recipients">
          <li v-for="r in item.recipients" :key="r.profile_guid" class="ppg-recipient">
            <span>{{ sentence(item, r) }}</span>
            <a-tag :color="statusOf(r).color">{{ statusOf(r).label }}</a-tag>
            <BaseButton
              v-if="r.rendered_message"
              size="small"
              variant="tertiary"
              data-testid="view-message"
              @click="emit('viewMessage', r.rendered_message)"
            >
              {{ PREVIEW_COPY.viewMessage }}
            </BaseButton>
          </li>
        </ul>
      </div>
      <BaseButton
        v-if="group.items.length > visibleItems(group).length"
        variant="secondary"
        data-testid="show-more"
        @click="showMore(group)"
      >
        {{ PREVIEW_COPY.showMore(group.items.length - visibleItems(group).length) }}
      </BaseButton>
    </a-collapse-panel>
  </a-collapse>
</template>

<style scoped>
.ppg-item {
  padding: 8px 0;
  border-bottom: 1px solid var(--pp-border, #f0f0f0);
}

.ppg-recipients {
  margin: 4px 0 0;
  padding: 0;
  list-style: none;
}

.ppg-recipient {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 8px;
  padding: 2px 0;
}
</style>
