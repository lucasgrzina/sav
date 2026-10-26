<script
  setup
  lang="ts"
  generic="T extends Record<string, any>"
>
import {
  computed,
  useAttrs
} from 'vue'

import {
  Table as ATable,
  Spin as ASpin,
  Pagination as APagination
} from 'ant-design-vue'

import type {
  TableProps
} from 'ant-design-vue'

import BaseTableEmpty from '@/components/tables/BaseTableEmpty.vue'
import { useResponsive } from '@/core/composables/useResponsive'

defineOptions({
  inheritAttrs: false
})

/**
 * Props propias opcionales
 * del design system
 */
interface BaseDataTableProps
  extends /* @vue-ignore */ TableProps<T> {
  rounded?: boolean

  /**
   * Opt-in: below the `md` breakpoint render one card per row
   * instead of a horizontally scrolling table.
   */
  mobileCards?: boolean

  /** Column key rendered as the card title. Defaults to the first column. */
  mobileTitleKey?: string

  /** Column keys rendered as label/value pairs in the card (2-3 recommended). */
  mobileSecondaryKeys?: string[]

  /** Column key whose cell holds the row actions. */
  mobileActionsKey?: string
}

interface MobileColumn {
  key?: string | number
  title?: unknown
}

interface RowHandlers {
  onClick?: (event: MouseEvent) => void
}

/**
 * Props
 */
const props = withDefaults(
  defineProps<BaseDataTableProps>(),
  {
    rounded: true,
    mobileCards: false,
    mobileTitleKey: undefined,
    mobileSecondaryKeys: () => [],
    mobileActionsKey: 'actions'
  }
)

/**
 * Hereda:
 * - eventos
 * - attrs
 * - listeners
 * de a-table
 */
const attrs = useAttrs()

const { isMobile } = useResponsive()

const showCards = computed(
  () => props.mobileCards && isMobile.value
)

/**
 * Attrs are not reactive, so everything derived from them is a plain
 * function evaluated on render (the component re-renders when attrs change).
 * Attrs arrive as written by the parent (camelCase or kebab-case).
 */
function readAttr<V>(
  camel: string,
  kebab: string
): V | undefined {
  return (attrs[camel] ?? attrs[kebab]) as V | undefined
}

function getColumns(): MobileColumn[] {
  return readAttr<MobileColumn[]>('columns', 'columns') ?? []
}

function getRecords(): T[] {
  return readAttr<T[]>('dataSource', 'data-source') ?? []
}

function isLoading(): boolean {
  return Boolean(readAttr<boolean>('loading', 'loading'))
}

function getTitleColumn(): MobileColumn | undefined {
  const columns = getColumns()

  return columns.find(c => c.key === props.mobileTitleKey) ?? columns[0]
}

function getSecondaryColumns(): MobileColumn[] {
  const columns = getColumns()

  return props.mobileSecondaryKeys
    .map(key => columns.find(c => c.key === key))
    .filter((c): c is MobileColumn => c !== undefined)
}

function getActionsColumn(): MobileColumn | undefined {
  return getColumns().find(c => c.key === props.mobileActionsKey)
}

function rowKeyOf(record: T, index: number): string | number {
  const rowKey = readAttr<string | ((r: T) => string | number)>('rowKey', 'row-key')

  if (typeof rowKey === 'function') return rowKey(record)
  if (typeof rowKey === 'string') return record[rowKey] as string | number

  return index
}

function cellValue(record: T, column: MobileColumn): unknown {
  return column.key === undefined ? undefined : record[String(column.key)]
}

function columnLabel(column: MobileColumn): string {
  return typeof column.title === 'string' ? column.title : ''
}

/**
 * Keeps the row click contract of a-table (customRow.onClick).
 */
function handleCardClick(
  record: T,
  index: number,
  event: MouseEvent
): void {
  const customRow = readAttr<(r: T, i: number) => RowHandlers>('customRow', 'custom-row')

  customRow?.(record, index)?.onClick?.(event)
}

function hasRowClick(): boolean {
  return Boolean(readAttr('customRow', 'custom-row'))
}

/**
 * Pagination (object form) is rendered below the cards,
 * and forwarded through the table's `change` listener.
 */
function getPagination(): Record<string, number> | null {
  const pagination = readAttr<false | Record<string, number>>('pagination', 'pagination')

  return pagination && typeof pagination === 'object'
    ? pagination
    : null
}

function handlePageChange(
  current: number,
  pageSize: number
): void {
  const onChange = attrs.onChange as
    | ((pagination: { current: number, pageSize: number }) => void)
    | undefined

  onChange?.({ current, pageSize })
}

/**
 * Props forwarded to a-table (own mobile props excluded).
 */
const tableProps = computed(() => {
  const {
    mobileCards: _mobileCards,
    mobileTitleKey: _mobileTitleKey,
    mobileSecondaryKeys: _mobileSecondaryKeys,
    mobileActionsKey: _mobileActionsKey,
    ...rest
  } = props

  return rest
})

/**
 * Clases internas
 */
const classes = computed(() => [
  'base-data-table',

  {
    'base-data-table--rounded':
      props.rounded
  }
])
</script>

<template>
  <div :class="classes">
    <ASpin
      v-if="showCards"
      :spinning="isLoading()"
    >
      <ul
        v-if="getRecords().length"
        class="base-data-table__cards"
      >
        <li
          v-for="(record, index) in getRecords()"
          :key="rowKeyOf(record, index)"
          class="base-data-table__card"
          :class="{ 'base-data-table__card--clickable': hasRowClick() }"
          data-test="mobile-card"
          @click="handleCardClick(record, index, $event)"
        >
          <div
            v-if="getTitleColumn()"
            class="base-data-table__card-title"
          >
            <slot
              name="bodyCell"
              :column="getTitleColumn()"
              :record="record"
              :index="index"
              :text="cellValue(record, getTitleColumn()!)"
            >
              {{ cellValue(record, getTitleColumn()!) }}
            </slot>
          </div>

          <dl
            v-if="getSecondaryColumns().length"
            class="base-data-table__card-fields"
          >
            <div
              v-for="column in getSecondaryColumns()"
              :key="column.key"
              class="base-data-table__card-field"
            >
              <dt>{{ columnLabel(column) }}</dt>
              <dd>
                <slot
                  name="bodyCell"
                  :column="column"
                  :record="record"
                  :index="index"
                  :text="cellValue(record, column)"
                >
                  {{ cellValue(record, column) }}
                </slot>
              </dd>
            </div>
          </dl>

          <div
            v-if="getActionsColumn()"
            class="base-data-table__card-actions"
            data-test="mobile-card-actions"
            @click.stop
          >
            <slot
              name="bodyCell"
              :column="getActionsColumn()"
              :record="record"
              :index="index"
              :text="undefined"
            />
          </div>
        </li>
      </ul>

      <BaseTableEmpty v-else-if="!isLoading()" />

      <div
        v-if="getPagination() && getRecords().length"
        class="base-data-table__pagination"
      >
        <APagination
          :current="getPagination()?.current"
          :page-size="getPagination()?.pageSize"
          :total="getPagination()?.total"
          size="small"
          simple
          @change="handlePageChange"
        />
      </div>
    </ASpin>

    <ATable
      v-else
      v-bind="tableProps"
      v-bind.attrs="attrs"
      :scroll="attrs.scroll ?? { x: 'max-content' }"
    >
      <!-- Forward ALL slots -->
      <template
        v-for="(_, slot) in $slots"
        #[slot]="scope"
      >
        <slot
          :name="slot"
          v-bind="scope"
        />
      </template>
    </ATable>
  </div>
</template>

<style scoped>
.base-data-table {
  @apply overflow-hidden;
  background:    var(--dt-card, #0E2038);
  border:        1px solid var(--dt-border, rgba(26,229,160,0.12));
  border-radius: var(--dt-radius-xl, 16px);
  transition:    background 0.25s ease, border-color 0.25s ease;
}

.base-data-table--rounded {
  border-radius: var(--dt-radius-xl, 16px);
}

.base-data-table__cards {
  @apply m-0 flex list-none flex-col p-0;
}

.base-data-table__card {
  @apply flex flex-col gap-2 p-4;
  border-bottom: 1px solid var(--dt-border, rgba(26,229,160,0.12));
}

.base-data-table__card:last-child {
  border-bottom: none;
}

.base-data-table__card--clickable {
  @apply cursor-pointer;
}

.base-data-table__card-title {
  @apply min-w-0 break-words text-base;
}

.base-data-table__card-fields {
  @apply m-0 flex flex-col gap-1;
}

.base-data-table__card-field {
  @apply flex min-w-0 items-baseline justify-between gap-3 text-sm;
}

.base-data-table__card-field dt {
  @apply shrink-0;
  color: var(--dt-muted, #6B8CAE);
}

.base-data-table__card-field dd {
  @apply m-0 min-w-0 break-words text-right;
  color: var(--dt-text, #C8E2EF);
}

.base-data-table__card-actions {
  @apply mt-1 flex justify-end;
}

/* Touch targets: at least 40px for any action inside a card */
.base-data-table__card-actions :deep(button),
.base-data-table__card-actions :deep(.ant-btn) {
  min-width: 40px;
  min-height: 40px;
}

.base-data-table__pagination {
  @apply flex justify-center p-3;
}
</style>
