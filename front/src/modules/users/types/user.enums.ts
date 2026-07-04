// Status real del backend
export const USER_STATUS_LABELS: Record<string, string> = {
  verified: 'Verificado',
  unverified: 'No verificado',
  locked: 'Bloqueado',
}

export const USER_STATUS_COLORS: Record<string, string> = {
  verified: 'success',
  unverified: 'warning',
  locked: 'error',
}

export const USER_EXPORT_COLUMNS: { key: string; label: string }[]  = [
  { key: 'guid',          label: 'ID'             },
  { key: 'first_name',    label: 'Nombre'         },
  { key: 'last_name',     label: 'Apellido'       },
  { key: 'email',         label: 'Email'          },
  { key: 'status',        label: 'Estado'         },
  { key: 'last_login_at', label: 'Último login'   },
  { key: 'created_at',    label: 'Fecha de alta'  },
]
