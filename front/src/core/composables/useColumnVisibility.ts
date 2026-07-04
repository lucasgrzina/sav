import { computed, ref, shallowRef, watch } from 'vue'

export interface TableColumnDef {
  key: string
  title: string
  dataIndex?: string
  width?: number
  fixed?: 'left' | 'right'
  alwaysVisible?: boolean
}

export function useColumnVisibility(storageKey: string, allColumns: TableColumnDef[]) {
  const selectable = allColumns.filter(c => !c.alwaysVisible)

  const stored = localStorage.getItem(`col-vis:${storageKey}`)
  const initialKeys: string[] = stored
    ? JSON.parse(stored)
    : selectable.map(c => c.key)

  const visibleKeys = ref<string[]>(initialKeys)

  watch(visibleKeys, (val) => {
    localStorage.setItem(`col-vis:${storageKey}`, JSON.stringify(val))
  }, { deep: true })

  const visibleColumns = computed(() =>
    allColumns.filter(c => c.alwaysVisible || visibleKeys.value.includes(c.key))
  )

  const isDrawerOpen = shallowRef(false)

  return {
    visibleKeys,
    visibleColumns,
    selectableColumns: selectable,
    isDrawerOpen,
  }
}
