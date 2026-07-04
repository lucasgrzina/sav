export interface SelectOption {
  label: string
  value: string | number
  disabled?: boolean
}

export interface TableColumn<T = unknown> {
  key: keyof T | string
  title: string
  sortable?: boolean
  width?: number | string
}

export interface ModalConfig {
  title: string
  width?: number | string
}
